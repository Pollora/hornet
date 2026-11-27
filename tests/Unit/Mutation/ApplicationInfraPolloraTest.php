<?php

declare(strict_types=1);

use Pollora\Doctor\Domain\Enums\CheckStatus;
use Pollora\Doctor\Domain\Enums\RunContext;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\HookServiceException;
use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Login\TransientAttemptStore;
use Pollora\Hornet\Infrastructure\Pollora\ConfiguredApplication;
use Pollora\Hornet\Infrastructure\Pollora\CorePatches;
use Pollora\Hornet\Infrastructure\Pollora\DoctorSecurityCheck;
use Pollora\Hornet\Infrastructure\Pollora\PolloraHooks;
use Pollora\Hornet\Tests\Fixtures\FakeSite;

// The doctor

it('says what to run when the first finding has no fix, and lists ten without a rest', function (): void {
    $findings = array_map(static fn (int $n): Finding => Finding::fail('c'.$n, Severity::Low, 'Finding '.$n.'.'), range(1, 10));

    $result = (new DoctorSecurityCheck(static fn (): Report => new Report($findings)))->run(RunContext::Console);
    $fine = (new DoctorSecurityCheck(static fn (): Report => new Report([Finding::pass('a', 'x')])))->run(RunContext::Console);

    expect($result->details)->toHaveCount(10)
        ->and($result->fix)->toBe('Run `wp hornet status` for the detail. (`wp hornet status` lists every finding.)')
        ->and($result->status)->toBe(CheckStatus::Warning)
        ->and($fine->details)->toBe([]);
});

// The application's configuration

/**
 * @param  array<string, mixed>  $config
 */
function mutAppLaravel(array $config): ConfiguredApplication
{
    return new ConfiguredApplication(static fn (string $key): mixed => $config[$key] ?? null);
}

it('reads debug and the key as Laravel runs them', function (): void {
    expect(mutAppLaravel(['app.debug' => 'true'])->debug())->toBeTrue()
        ->and(mutAppLaravel(['app.debug' => 'false'])->debug())->toBeFalse()
        ->and(mutAppLaravel([])->debug())->toBeFalse()
        ->and(mutAppLaravel(['app.key' => 'base64:abc'])->hasKey())->toBeTrue()
        ->and(mutAppLaravel(['app.key' => '  '])->hasKey())->toBeFalse()
        ->and(mutAppLaravel(['app.key' => 5])->hasKey())->toBeFalse()
        ->and(mutAppLaravel([])->hasKey())->toBeFalse();
});

it('says whether Debugbar runs in production', function (array $config, bool $forced): void {
    expect(mutAppLaravel($config)->debugbarForced())->toBe($forced);
})->with([
    'not installed' => [[], false],
    'version 3, on' => [['debugbar.enabled' => 'true'], true],
    'version 3, off' => [['debugbar.enabled' => 'false'], false],
    'version 4, not forced' => [['debugbar.force_allow_enable' => false, 'debugbar.enabled' => true], false],
    'forced and on' => [['debugbar.force_allow_enable' => 'true', 'debugbar.enabled' => 'true'], true],
    'forced and off' => [['debugbar.force_allow_enable' => true, 'debugbar.enabled' => 'false', 'app.debug' => true], false],
    'forced, follows debug' => [['debugbar.force_allow_enable' => true, 'app.debug' => true], true],
    'forced, debug off' => [['debugbar.force_allow_enable' => true], false],
]);

it('says whether the bar is shown to some users only', function (): void {
    expect(mutAppLaravel(['debugbar-pollora.capability' => 'manage_options'])->debugbarRestricted())->toBeTrue()
        ->and(mutAppLaravel([])->debugbarRestricted())->toBeFalse();
});

// Hooks

it('attaches through the hook services with the priority and arguments, and refuses one that cannot', function (): void {
    $service = new class
    {
        public array $added = [];

        public function add(string $hook, callable $callback, int $priority, int $arguments): void
        {
            $this->added[] = [$hook, $priority, $arguments];
        }
    };
    $filter = clone $service;
    $hooks = new PolloraHooks($service, $filter);

    $hooks->addAction('init', static fn () => null);
    $hooks->addFilter('the_title', static fn () => null, 5, 3);

    expect($service->added)->toBe([['init', 10, 1]])->and($filter->added)->toBe([['the_title', 5, 3]])
        ->and(fn () => (new PolloraHooks(new stdClass, new stdClass))->addAction('init', static fn () => null))->toThrow(HookServiceException::class, 'stdClass');
});

// Patched core files

it('reads the files a patch changes, without the leading folders', function (): void {
    $patch = "--- a/x\r\n+++ b/wp-includes/l10n.php\t2026-01-01\r\n+++ b/wp-admin/a.php 2026\n+++ /dev/null\n+++ b/../escape.php\n+++ b\n++ b/not.php\n x +++ b/not.php\n";

    expect(CorePatches::touched($patch))->toBe(['wp-includes/l10n.php', 'wp-admin/a.php'])
        ->and(CorePatches::touched('+++ a/b/c.php', 2))->toBe(['c.php'])
        ->and(CorePatches::touched('+++ c.php', 0))->toBe(['c.php']);
});

it('takes only the patches whose hash the lock names, for a package of the core', function (): void {
    $root = sys_get_temp_dir().'/hornet-mut-'.bin2hex(random_bytes(6));
    mkdir($root.'/patches', 0777, true);
    $patch = static function (string $name, string $contents) use ($root): string {
        file_put_contents($root.'/patches/'.$name, $contents);

        return hash('sha256', $contents);
    };
    $one = $patch('one.patch', "+++ b/wp-includes/l10n.php\n");
    $deep = $patch('deep.patch', "+++ a/b/wp-load.php\n");
    $other = $patch('other.patch', "+++ b/wp-mail.php\n");
    $patch('unlisted.patch', "+++ b/wp-cron.php\n");
    $text = $patch('one.txt', "+++ b/wp-trackback.php\n");

    expect(CorePatches::in($root, [$root.'/patches']))->toBe([]);

    file_put_contents($root.'/patches.lock.json', json_encode(['patches' => [
        'johnpbloch/wordpress-core' => [['sha256' => $one], ['sha256' => $one], 'junk', ['sha256' => 5], ['sha256' => 'x'.$other], ['sha256' => $other.'x'], ['sha256' => $text]],
        'roots/wordpress-full' => [['sha256' => $deep, 'depth' => 2]],
        'some/other-package' => [['sha256' => $other]],
    ]]));

    expect(CorePatches::in($root, [$root.'/nope', $root.'/patches']))->toBe(['wp-includes/l10n.php', 'wp-load.php']);

    foreach (['nope', '{"patches":"x"}', '{"patches":{"roots/wordpress":"x"}}'] as $lock) {
        file_put_contents($root.'/patches.lock.json', $lock);

        expect(CorePatches::in($root, [$root.'/patches']))->toBe([]);
    }
});

it('finds no patch in a project that locks none', function (): void {
    expect(CorePatches::files())->toBe([]);
});

// The clock

it('tells the time of now, in UTC', function (): void {
    $before = time();
    $now = (new SystemClock)->now();

    expect($now->getTimezone()->getName())->toBe('UTC')->and($now->getTimestamp())->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(time());
});

// Where the failed logins are kept

function mutAppKey(int $n): string
{
    return hash('sha256', 'address'.$n);
}

it('keeps the failures where the site keeps such things, for as long as asked', function (bool $objectCache, bool $multisite): void {
    $site = FakeSite::install();
    $site->objectCache = $objectCache;
    $site->multisite = $multisite;
    $store = new TransientAttemptStore;
    $key = mutAppKey(1);

    $store->put($key, new Attempts(2, 5), 600);

    $where = $objectCache ? $site->cache : ($multisite ? $site->siteTransients : $site->transients);
    $name = $objectCache ? 'hornet-login:'.$key : 'hornet_lg_'.$key;

    expect($where)->toHaveKey($name)
        ->and($site->lifetimes[$name])->toBe(600)
        ->and($store->get($key)->failures)->toBe(2)
        ->and($site->globalGroups)->toBe($multisite ? ['hornet-login'] : [])
        // Not locked: nothing to list
        ->and($site->options)->toBe([])->and($site->networkOptions)->toBe([]);

    $store->forget($key);

    expect($store->get($key)->failures)->toBe(0);
})->with(['transients' => [false, false], 'network' => [false, true], 'object cache' => [true, false], 'network with a cache' => [true, true]]);

it('lists the lockouts so that all can be lifted, and counts those still locked', function (bool $multisite): void {
    $site = FakeSite::install();
    $site->multisite = $multisite;
    $store = new TransientAttemptStore;
    $store->put(mutAppKey(1), new Attempts(5, 1, time() + 900), 900);
    $store->put(mutAppKey(2), new Attempts(5, 1, time() + 900), 900);
    $options = fn (): array => $multisite ? $site->networkOptions : $site->options;
    $index = $options()['hornet_lockouts'];
    // One that has run out, and what is not a lockout
    $index[mutAppKey(3)] = time() - 10;
    $multisite ? $site->networkOptions['hornet_lockouts'] = [...$index, 'junk' => 5, mutAppKey(4) => 'x', 'x'.mutAppKey(5) => 5] : $site->options['hornet_lockouts'] = [...$index, 'junk' => 5, mutAppKey(4) => 'x', 'x'.mutAppKey(5) => 5];

    expect($index)->toHaveKeys([mutAppKey(1), mutAppKey(2)])
        ->and($store->forgetAll())->toBe(2)
        ->and($options())->not->toHaveKey('hornet_lockouts')
        ->and($store->get(mutAppKey(1))->failures)->toBe(0)
        ->and($store->get(mutAppKey(2))->failures)->toBe(0);
})->with([false, true]);

it('drops from the list the lockouts that ran out, and the one that is forgotten', function (): void {
    $site = FakeSite::install();
    $site->options['hornet_lockouts'] = [mutAppKey(3) => time() - 10, mutAppKey(4) => time() + 50];
    $store = new TransientAttemptStore;

    $store->put(mutAppKey(1), new Attempts(5, 1, time() + 900), 900);

    expect(array_keys($site->options['hornet_lockouts']))->toBe([mutAppKey(4), mutAppKey(1)])
        ->and($site->autoload['hornet_lockouts'])->toBeFalse();

    $store->forget(mutAppKey(1));

    expect(array_keys($site->options['hornet_lockouts']))->toBe([mutAppKey(4)]);
});

it('remembers five hundred lockouts, those that last longest', function (): void {
    $site = FakeSite::install();
    $index = [];

    foreach (range(1, 500) as $n) {
        $index[mutAppKey($n)] = time() + 1000 + $n;
    }

    $site->options['hornet_lockouts'] = array_reverse($index, true);
    (new TransientAttemptStore)->put(mutAppKey(501), new Attempts(5, 1, time() + 5000), 5000);
    $kept = $site->options['hornet_lockouts'];

    expect($kept)->toHaveCount(500)
        ->and($kept)->toHaveKeys([mutAppKey(2), mutAppKey(500), mutAppKey(501)])
        ->and($kept)->not->toHaveKey(mutAppKey(1));
});
