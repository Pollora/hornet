<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Abilities\AbilitiesModule;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminPageModule;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminRestModule;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\HardeningModule;
use Pollora\Hornet\Infrastructure\WordPress\HeadersModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalPrivacy;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TwoFactorJournalModule;
use Pollora\Hornet\Infrastructure\WordPress\LoginDatesModule;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\PasswordModule;
use Pollora\Hornet\Infrastructure\WordPress\RestAccessModule;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use Pollora\Hornet\Infrastructure\WordPress\SessionModule;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthModule;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorGateModule;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

beforeEach(function (): void {
    FakeSite::install();
    Functions\when('is_admin')->justReturn(false);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_doing_cron')->justReturn(false);
    Functions\when('wp_salt')->justReturn('salt');
});

/**
 * @return list<class-string>
 */
function moduleClasses(array $config): array
{
    return array_map(static fn (object $module): string => $module::class, Bootstrap::modules(Settings::fromArray($config), new FakeEnvironment));
}

it('attaches a module for each protection that is asked for', function (array $config, string $module): void {
    expect(moduleClasses($config))->toContain($module)
        ->and(moduleClasses([]))->not->toContain($module);
})->with([
    'hardening' => [['hardening' => ['xmlrpc' => true]], HardeningModule::class],
    'the closed REST API' => [['hardening' => ['restrict_rest_api' => true]], RestAccessModule::class],
    'headers' => [['headers' => ['content_type_options' => true]], HeadersModule::class],
    'throttle' => [['login' => ['throttle' => true]], LoginThrottleModule::class],
    'breached passwords' => [['passwords' => ['check_breached' => true]], PasswordModule::class],
    'a session length' => [['session' => ['lifetime' => 3600]], SessionModule::class],
    'a remembered session length' => [['session' => ['remember_lifetime' => 3600]], SessionModule::class],
    'two factors' => [['two_factor' => ['require_roles' => ['administrator']]], TwoFactorGateModule::class],
    'the journal' => [['journal' => ['enabled' => true]], JournalModule::class],
    'the second factor in the journal' => [['journal' => ['enabled' => true]], TwoFactorJournalModule::class],
    'the privacy tools of the journal' => [['journal' => ['enabled' => true]], JournalPrivacy::class],
    'abilities' => [['abilities' => ['enabled' => true]], AbilitiesModule::class],
]);

it('attaches everything at once, in an order that the journal and the scan rely on', function (): void {
    expect(moduleClasses(['journal' => ['enabled' => true], 'abilities' => ['enabled' => true], 'scanner' => ['schedule' => 'daily']]))->toBe([
        SiteHealthModule::class, LoginDatesModule::class, JournalModule::class, TwoFactorJournalModule::class, JournalPrivacy::class, ScanModule::class, AbilitiesModule::class, AdminRestModule::class,
    ]);
});

it('listens for the scan when an agent may ask for one from the cron', function (): void {
    Functions\when('wp_doing_cron')->justReturn(true);

    expect(moduleClasses(['admin' => false, 'abilities' => ['enabled' => true, 'writes' => true]]))->toContain(ScanModule::class)
        ->and(moduleClasses(['admin' => false, 'abilities' => ['enabled' => true]]))->not->toContain(ScanModule::class)
        ->and(moduleClasses(['admin' => false]))->not->toContain(ScanModule::class, AdminRestModule::class, AdminPageModule::class);
});

it('starts on plugins_loaded with the settings of the site', function (): void {
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('did_action')->justReturn(1);
    $added = [];

    foreach (['add_action', 'add_filter'] as $function) {
        Functions\when($function)->alias(function (string $hook) use (&$added): bool {
            $added[] = $hook;

            return true;
        });
    }

    Functions\when('config')->justReturn(['hardening' => ['xmlrpc' => true]]);
    Bootstrap::fromWordPress();

    expect($added)->toContain('site_status_tests', 'xmlrpc_enabled');
});

it('stays off and says why on Site Health when the settings of the site cannot be read', function (): void {
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('did_action')->justReturn(1);
    $added = [];

    foreach (['add_action', 'add_filter'] as $function) {
        Functions\when($function)->alias(function (string $hook) use (&$added): bool {
            $added[] = $hook;

            return true;
        });
    }

    Functions\when('config')->justReturn(['enable_everything' => true]);
    Bootstrap::fromWordPress();

    expect($added)->toBe(['site_status_tests']);
});

it('waits for plugins_loaded at the first priority', function (): void {
    Functions\when('did_action')->justReturn(0);
    $added = [];
    Functions\when('add_action')->alias(function (string $hook, callable $start, int $priority) use (&$added): bool {
        $added[] = [$hook, $priority];

        return true;
    });

    Bootstrap::fromWordPress();

    expect($added)->toBe([['plugins_loaded', 1]]);
});

it('registers the journal hooks on boot', function (): void {
    $hooks = new RecordingHooks;
    Bootstrap::boot(Settings::fromArray(['journal' => ['enabled' => true]]), $hooks, new FakeEnvironment);

    expect(array_column($hooks->registered, 'hook'))->toContain('wp_login', 'wp_privacy_personal_data_erasers', 'rest_api_init');
});
