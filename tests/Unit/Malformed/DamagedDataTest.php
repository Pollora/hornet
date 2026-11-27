<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Scalar;
use Pollora\Hornet\Domain\Scan\CachedAdvisoryDatabase;
use Pollora\Hornet\Domain\Scan\ComposerPackage;
use Pollora\Hornet\Infrastructure\Pollora\HornetServiceProvider;
use Pollora\Hornet\Infrastructure\Pollora\PolloraHooks;
use Pollora\Hornet\Infrastructure\WordPress\HardeningModule;
use Pollora\Hornet\Infrastructure\WordPress\WpPluginInventory;
use Pollora\Hornet\Tests\Fixtures\InMemoryAdvisoryCache;
use Pollora\Hornet\Tests\Fixtures\ScriptedAdvisoryDatabase;

/*
 * What comes from a cache, an option, the configuration or another piece of code is not always of the shape it should be.
 */

it('asks the database again when what it kept of a release is damaged, rather than calling it clean', function (array $kept): void {
    $inner = new ScriptedAdvisoryDatabase(['vendor/package@1.0.0' => ['GHSA-aaaa-bbbb-cccc']]);
    $cache = new InMemoryAdvisoryCache;
    $cache->stored['match:vendor/package:1.0.0'] = $kept;

    $answers = (new CachedAdvisoryDatabase($inner, $cache))->affecting([new ComposerPackage('vendor/package', '1.0.0')]);

    expect($inner->batches)->toBe([['vendor/package@1.0.0']])
        ->and($answers)->toBe([['GHSA-aaaa-bbbb-cccc' => '2026-01-01T00:00:00Z']]);
})->with([
    'a number for a date' => [['GHSA-aaaa-bbbb-cccc' => 5]],
    'nothing for a date' => [['GHSA-aaaa-bbbb-cccc' => null]],
    'a list for a date' => [['GHSA-aaaa-bbbb-cccc' => ['2026-01-01']]],
    'a list instead of identifiers' => [['GHSA-aaaa-bbbb-cccc']],
]);

it('keeps believing a release it kept as concerned by nothing', function (): void {
    $inner = new ScriptedAdvisoryDatabase;
    $cache = new InMemoryAdvisoryCache;
    $cache->stored['match:vendor/package:1.0.0'] = [];

    expect((new CachedAdvisoryDatabase($inner, $cache))->affecting([new ComposerPackage('vendor/package', '1.0.0')]))->toBe([[]])
        ->and($inner->batches)->toBe([]);
});

it('keeps a plugin whose headers are not text, so that it is still looked at', function (): void {
    Functions\when('get_plugins')->justReturn(['akismet/akismet.php' => ['Version' => '5.0', 'Name' => ['Akismet'], 'UpdateURI' => null]]);
    Functions\when('is_plugin_active')->justReturn(true);

    $plugins = (new WpPluginInventory)->plugins();

    expect($plugins)->toHaveCount(1)
        ->and($plugins[0]->slug)->toBe('akismet')
        ->and($plugins[0]->version)->toBe('5.0')
        ->and($plugins[0]->name)->toBe('')
        ->and($plugins[0]->updateUri)->toBe('');
});

it('stops an author query it cannot turn into a 404', function (): void {
    $_GET['author'] = '1';
    unset($GLOBALS['wp_query']);
    Functions\when('is_user_logged_in')->justReturn(false);
    Functions\expect('wp_die')->once()->with('', '', ['response' => 404])->andThrow(new RuntimeException('died'));

    try {
        expect(fn () => HardeningModule::refuseAuthorQuery())->toThrow(RuntimeException::class, 'died');
    } finally {
        unset($_GET['author']);
    }
});

it('says so when the application configuration of Hornet is not an array, instead of failing the request', function (): void {
    $app = new class
    {
        public function configPath(string $path = ''): string
        {
            return '/nowhere/config';
        }

        public function bound(string $abstract): bool
        {
            return true;
        }

        public function make(string $abstract): object
        {
            if ($abstract === 'config') {
                return new class
                {
                    public function get(string $key, mixed $default = null): mixed
                    {
                        return 'everything';
                    }
                };
            }

            return new class
            {
                /** @var list<string> */
                public static array $hooks = [];

                public function add(string $hook, callable $callback, int $priority, int $args): void
                {
                    self::$hooks[] = $hook;
                    self::$hooks[] = $hook === 'site_status_tests' ? $callback([])['direct']['hornet_settings']['test']()['description'] : '';
                }
            };
        }
    };
    Functions\when('esc_html')->returnArg();

    (new HornetServiceProvider($app))->start();
    $hooks = $app->make('hooks')::$hooks;

    expect($hooks[0])->toBe('site_status_tests')->and($hooks[1])->toContain('pollora.hornet')->toContain('an array');
});

it('refuses a hook service that cannot attach anything', function (): void {
    $hooks = new PolloraHooks(new stdClass, new stdClass);

    expect(fn () => $hooks->addAction('init', fn () => null))->toThrow(LogicException::class, 'add()');
});

it('reads a scalar as PHP casts it, and nothing else', function (mixed $value, string $text, int $number): void {
    expect(Scalar::string($value, 'none'))->toBe($text)->and(Scalar::int($value, -1))->toBe($number);
})->with([
    'text' => ['12', '12', 12],
    'a flag' => [true, '1', 1],
    'a number' => [3, '3', 3],
    'a list' => [['a'], 'none', -1],
    'nothing' => [null, 'none', -1],
    'an object' => [new stdClass, 'none', -1],
]);
