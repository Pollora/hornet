<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminPageModule;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\Module;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorGateModule;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
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

it('serves the files of the screen from the assets of the package', function (): void {
    $module = mutWpModule(AdminPageModule::class, []);

    expect((new ReflectionProperty($module, 'assets'))->getValue($module))->toBe(dirname(__DIR__, 3).'/assets/admin');
});
