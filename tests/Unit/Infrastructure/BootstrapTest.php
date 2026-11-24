<?php

declare(strict_types=1);

use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
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
