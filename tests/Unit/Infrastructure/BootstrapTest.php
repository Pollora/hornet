<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\NotificationModule;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use Pollora\Hornet\Infrastructure\WordPress\SettingsErrorModule;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthModule;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

it('attaches Site Health by default', function (): void {
    $modules = Bootstrap::modules(Settings::fromArray([]), new FakeEnvironment);

    expect($modules)->toHaveCount(1)->and($modules[0])->toBeInstanceOf(SiteHealthModule::class);
});

it('attaches nothing when Hornet is switched off', function (): void {
    expect(Bootstrap::modules(Settings::fromArray(['enabled' => false]), new FakeEnvironment))->toBe([]);
});

it('attaches nothing when Site Health is off', function (): void {
    expect(Bootstrap::modules(Settings::fromArray(['site_health' => false]), new FakeEnvironment))->toBe([]);
});

it('keeps the site running and says why when the settings cannot be read', function (): void {
    $modules = Bootstrap::modules(null, new FakeEnvironment, 'Unknown Hornet setting "enable".');

    expect($modules)->toHaveCount(1)->and($modules[0])->toBeInstanceOf(SettingsErrorModule::class);
});

it('keeps the site running when a setting names a check that does not exist', function (): void {
    $modules = Bootstrap::modules(Settings::fromArray(['disabled_checks' => ['saltz']]), new FakeEnvironment);

    expect($modules)->toHaveCount(1)->and($modules[0])->toBeInstanceOf(SettingsErrorModule::class);
});

it('registers every module on the hooks it is given', function (): void {
    $hooks = new RecordingHooks;
    Bootstrap::boot(Settings::fromArray([]), $hooks, new FakeEnvironment);

    expect(array_column($hooks->registered, 'hook'))->toBe(['site_status_tests']);
});

it('starts at once when plugins are loaded, and waits otherwise', function (): void {
    Brain\Monkey\Functions\when('get_current_blog_id')->justReturn(1);
    Brain\Monkey\Functions\when('did_action')->justReturn(0);
    Brain\Monkey\Actions\expectAdded('plugins_loaded')->once();

    Bootstrap::fromWordPress();
});

it('attaches the cron when a channel is configured', function (): void {
    $settings = Settings::fromArray(['notifications' => ['channels' => ['team' => ['type' => 'mail', 'to' => 'ops@example.com']]]]);
    $modules = Bootstrap::modules($settings, new FakeEnvironment);

    expect(array_map(static fn ($module): string => $module::class, $modules))->toBe([SiteHealthModule::class, NotificationModule::class]);
});

it('keeps the site running when a channel is wrong or a setting names a check that does not exist', function (): void {
    $settings = Settings::fromArray(['disabled_checks' => ['saltz'], 'notifications' => ['channels' => ['team' => ['type' => 'mail', 'to' => 'ops@example.com']]]]);

    expect(Bootstrap::modules($settings, new FakeEnvironment)[0])->toBeInstanceOf(SettingsErrorModule::class);
});

it('attaches the login throttle when it is on, and not otherwise', function (): void {
    Brain\Monkey\Functions\when('wp_salt')->justReturn('salt');
    $on = Bootstrap::modules(Settings::fromArray(['login' => ['throttle' => true]]), new FakeEnvironment);
    $off = Bootstrap::modules(Settings::fromArray(['login' => ['throttle' => false]]), new FakeEnvironment);

    expect(array_map(static fn ($module): string => $module::class, $on))->toContain(LoginThrottleModule::class)
        ->and(array_map(static fn ($module): string => $module::class, $off))->not->toContain(LoginThrottleModule::class);
});

it('lets the host say who is asking: Pollora hands over the request of Laravel', function (): void {
    Brain\Monkey\Functions\when('wp_salt')->justReturn('salt');
    Brain\Monkey\Functions\when('wp_using_ext_object_cache')->justReturn(false);
    Brain\Monkey\Functions\when('get_transient')->justReturn(false);
    $asked = 0;
    $modules = Bootstrap::modules(Settings::fromArray(['login' => ['throttle' => true]]), new FakeEnvironment, null, static function () use (&$asked): ?ClientIp {
        $asked++;

        return new ClientIp('203.0.113.7', '10.0.0.5');
    });
    $throttle = array_values(array_filter($modules, static fn ($module): bool => $module instanceof LoginThrottleModule))[0];
    $throttle->gate('user');

    expect($asked)->toBe(1);
});

it('attaches the scan to the cron only when the owner asks for a schedule', function (): void {
    Functions\when('wp_next_scheduled')->justReturn(false);

    $classes = static fn (array $settings): array => array_map(static fn ($module): string => $module::class, Bootstrap::modules(Settings::fromArray($settings), new FakeEnvironment));

    expect($classes(['scanner' => ['schedule' => 'daily']]))->toBe([SiteHealthModule::class, ScanModule::class])
        ->and($classes(['scanner' => ['schedule' => 'off']]))->toBe([SiteHealthModule::class])
        ->and($classes([]))->toBe([SiteHealthModule::class]);
});

it('keeps the scan attached while its event is still in the cron, so that turning the schedule off takes it out', function (): void {
    Functions\when('wp_next_scheduled')->justReturn(1893456000);

    $modules = Bootstrap::modules(Settings::fromArray(['scanner' => ['schedule' => 'off']]), new FakeEnvironment);

    expect(array_map(static fn ($module): string => $module::class, $modules))->toBe([SiteHealthModule::class, ScanModule::class]);
});
