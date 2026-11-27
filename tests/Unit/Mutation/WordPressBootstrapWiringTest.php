<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Pollora\ConfiguredApplication;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminPageModule;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\ErrorDisplayModule;
use Pollora\Hornet\Infrastructure\WordPress\HostApplication;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\Module;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorGateModule;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;

/*
 * What Bootstrap hands to the modules it builds: the salt of the stored addresses, who the current user is, where the files of the
 * screen are.
 */

beforeEach(function (): void {
    $this->site = FakeSite::install();
    Functions\when('is_admin')->justReturn(true);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_doing_cron')->justReturn(false);
    Functions\when('wp_salt')->alias(static fn (string $scheme): string => $scheme.'-salt-of-the-site');
});

/** @param array<string, mixed> $config */
function mutWpModule(string $class, array $config, ?Closure $client = null): Module
{
    foreach (Bootstrap::modules(Settings::fromArray($config), new FakeEnvironment, null, $client) as $module) {
        if ($module instanceof $class) {
            return $module;
        }
    }

    throw new LogicException('Not built: '.$class);
}

it('stores the failures of a login under a hash salted for this site and for this use', function (): void {
    $client = new ClientIp('203.0.113.7', '203.0.113.7');
    $module = mutWpModule(LoginThrottleModule::class, ['login' => ['throttle' => true]], static fn (): ClientIp => $client);

    $module->failed();

    expect(array_keys($this->site->transients))->toHaveCount(1)
        ->and(array_keys($this->site->transients)[0])->toContain(substr($client->key('auth-salt-of-the-sitehornet-login'), 0, 20));
});

it('asks the second factor of the user who is logged in, and of nobody else', function (bool $loggedIn, int $asked): void {
    Functions\when('is_user_logged_in')->justReturn($loggedIn);
    $calls = 0;
    Functions\when('wp_get_current_user')->alias(function () use (&$calls): FakeWpUser {
        $calls++;
        $user = FakeWpUser::named(3, 'ana');
        $user->roles = ['subscriber'];

        return $user;
    });
    $module = mutWpModule(TwoFactorGateModule::class, ['two_factor' => ['require_roles' => ['administrator']]]);

    $module->gate();

    expect($calls)->toBe($asked);
})->with([[true, 1], [false, 0]]);

it('serves the screen from the compiled files and the languages of the package', function (): void {
    $enqueued = [];
    Functions\when('add_management_page')->justReturn('the-screen');
    Functions\when('wp_script_is')->justReturn(true);
    Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin.php');
    Functions\when('add_query_arg')->alias(static fn (array $arguments, string $url): string => $url.'?'.http_build_query($arguments));
    Functions\when('wp_enqueue_script')->alias(function (string $handle, string $url, array $dependencies, string $version) use (&$enqueued): void {
        $enqueued['version'] = $version;
    });
    Functions\when('wp_set_script_translations')->alias(function (string $handle, string $domain, string $path) use (&$enqueued): void {
        $enqueued['languages'] = $path;
    });
    Functions\when('wp_enqueue_style')->justReturn(null);
    Functions\when('is_rtl')->justReturn(false);
    $module = mutWpModule(AdminPageModule::class, []);

    $module->menu();
    $module->enqueue('the-screen');

    $package = dirname(__DIR__, 3);

    expect($enqueued)->toBe(['version' => (include $package.'/assets/admin/index.asset.php')['version'], 'languages' => $package.'/languages']);
});

it('journals the address that the host says, when it knows better than the server variables', function (): void {
    $GLOBALS['wpdb'] = $database = new FakeWpdb;
    Functions\when('wp_doing_ajax')->justReturn(false);
    Functions\when('get_current_user_id')->justReturn(0);
    Functions\when('wp_get_current_user')->justReturn(new FakeWpUser);
    Functions\when('get_user_by')->justReturn(false);
    $module = mutWpModule(JournalModule::class, ['journal' => ['enabled' => true]], static fn (): ClientIp => new ClientIp('198.51.100.9', '10.0.0.1'));

    $module->loginFailed('someone');

    expect($database->inserted)->toHaveCount(1)->and($database->inserted[0]['data']['ip'])->toBe('198.51.100.0');
});

it('does not watch the display of errors where Laravel sets it', function (): void {
    HostApplication::set(new ConfiguredApplication(static fn (): mixed => null));

    expect(array_map(static fn (Module $module): string => $module::class, Bootstrap::modules(Settings::fromArray([]), new FakeEnvironment)))
        ->not->toContain(ErrorDisplayModule::class);

    HostApplication::set(null);

    expect(array_map(static fn (Module $module): string => $module::class, Bootstrap::modules(Settings::fromArray([]), new FakeEnvironment)))
        ->toContain(ErrorDisplayModule::class);
});
