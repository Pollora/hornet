<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Application\Password\BreachCheck;
use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Infrastructure\Config\HardeningSettings;
use Pollora\Hornet\Infrastructure\Config\LoginSettings;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminPageModule;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminRestModule;
use Pollora\Hornet\Infrastructure\WordPress\ErrorDisplayModule;
use Pollora\Hornet\Infrastructure\WordPress\ErrorDisplayNote;
use Pollora\Hornet\Infrastructure\WordPress\HardeningModule;
use Pollora\Hornet\Infrastructure\WordPress\HeadersModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\DroppedSiteModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalPrivacy;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TwoFactorJournalModule;
use Pollora\Hornet\Infrastructure\WordPress\LoginDates;
use Pollora\Hornet\Infrastructure\WordPress\LoginDatesModule;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\NotificationModule;
use Pollora\Hornet\Infrastructure\WordPress\PasswordModule;
use Pollora\Hornet\Infrastructure\WordPress\RestAccessModule;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use Pollora\Hornet\Infrastructure\WordPress\SessionModule;
use Pollora\Hornet\Infrastructure\WordPress\SettingsErrorModule;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthInfo;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthModule;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorEnrollment;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorGateModule;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;
use Pollora\Hornet\Tests\Fixtures\AdminSite;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\FakeRequestContext;
use Pollora\Hornet\Tests\Fixtures\InMemoryAttemptStore;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStateStore;
use Pollora\Hornet\Tests\Fixtures\InMemoryWindowCounter;
use Pollora\Hornet\Tests\Fixtures\MutationWordPressHooks;

/*
 * The name, the priority and the number of arguments of everything that is attached to WordPress. A callback that asks for fewer
 * arguments than it needs fails on a real site, and a priority decides who has the last word.
 */

function mutWpJournalModule(): JournalModule
{
    $journal = new InMemoryJournal;
    $clock = new FakeClock;

    return new JournalModule(new JournalRecorder($journal, new FakeRequestContext, $clock), $journal, $clock, 30, new FloodGuard(new InMemoryWindowCounter), static fn (): string => '203.0.113.77');
}

it('hands the priority and the number of arguments to WordPress as they are', function (): void {
    $callback = static fn (): null => null;
    Functions\expect('add_action')->once()->with('init', $callback, 7, 3);
    Functions\expect('add_filter')->once()->with('the_title', $callback, 99, 2);

    $hooks = new WordPressHooks;
    $hooks->addAction('init', $callback, 7, 3);
    $hooks->addFilter('the_title', $callback, 99, 2);
});

it('defaults to priority 10 and one argument', function (): void {
    $callback = static fn (): null => null;
    Functions\expect('add_action')->once()->with('init', $callback, 10, 1);
    Functions\expect('add_filter')->once()->with('the_title', $callback, 10, 1);

    $hooks = new WordPressHooks;
    $hooks->addAction('init', $callback);
    $hooks->addFilter('the_title', $callback);
});

it('locks logins late, and listens to every door a password comes through', function (): void {
    $module = new LoginThrottleModule(LoginSettings::fromArray(['throttle' => true]), new InMemoryAttemptStore, new FakeClock, static fn (): null => null, static fn (): null => null, 'salt');

    expect(MutationWordPressHooks::of($module)->table)->toBe([
        ['filter', 'authenticate', 100, 1],
        ['action', 'wp_login_failed', 10, 1],
        ['action', 'application_password_failed_authentication', 10, 1],
        ['action', 'wp_authenticate_application_password_errors', 10, 1],
        ['action', 'wp_login', 10, 1],
    ]);
});

it('sends to the second factor before anything else of the dashboard runs', function (): void {
    $module = new TwoFactorGateModule(Settings::fromArray([])->accounts, new TwoFactorEnrollment, new FakeClock, static fn (): null => null, static fn (): null => null);

    expect(MutationWordPressHooks::of($module)->table)->toBe([
        ['action', 'admin_init', 1, 1],
        ['action', 'admin_notices', 10, 1],
    ]);
});

it('closes the REST API after every other check, and answers the preflight before WordPress describes the route', function (): void {
    $module = new RestAccessModule(HardeningSettings::fromArray([])->restAllowList(false));

    expect(MutationWordPressHooks::of($module)->table)->toBe([
        ['filter', 'rest_authentication_errors', PHP_INT_MAX, 1],
        ['filter', 'rest_index', 10, 1],
        ['filter', 'rest_pre_dispatch', 9, 1],
    ]);
});

it('attaches each piece of hardening with what its callback needs', function (string $piece, array $table): void {
    $module = new HardeningModule(HardeningSettings::fromArray([$piece => true]));

    expect(MutationWordPressHooks::of($module)->table)->toBe($table);
})->with([
    'xmlrpc' => ['xmlrpc', [['filter', 'xmlrpc_enabled', 10, 1], ['filter', 'xmlrpc_methods', 10, 1], ['filter', 'wp_headers', 10, 1], ['action', 'init', 10, 1]]],
    'users' => ['user_enumeration', [['action', 'template_redirect', 1, 1], ['filter', 'rest_endpoints', 10, 1], ['filter', 'wp_sitemaps_add_provider', 10, 2], ['filter', 'oembed_response_data', 10, 1]]],
    'version' => ['hide_version', [['filter', 'the_generator', 10, 1], ['action', 'init', 10, 1]]],
    'file editor' => ['file_editor', [['filter', 'map_meta_cap', 10, 2]]],
]);

it('sends the headers after those of WordPress', function (): void {
    $module = new HeadersModule(Settings::fromArray(['headers' => ['referrer_policy' => 'no-referrer']])->headers);

    expect(MutationWordPressHooks::of($module)->table)->toBe([
        ['filter', 'wp_headers', 10, 1],
        ['action', 'admin_init', 20, 1],
        ['action', 'login_init', 20, 1],
        ['filter', 'admin_referrer_policy', 10, 1],
    ]);
});

it('attaches the journal with what each event gives, and the table before anything else', function (): void {
    $table = MutationWordPressHooks::of(mutWpJournalModule())->table;
    $rows = array_map(static fn (array $row): string => implode(' ', $row), $table);

    expect($rows)->toBe([
        'action init 0 1',
        'action init 10 1',
        'action pollora/hornet/purge_journal 10 1',
        'action wp_login 10 2',
        'action wp_login_failed 10 2',
        'action after_password_reset 10 1',
        'filter wp_pre_insert_user_data 10 2',
        'action user_register 10 1',
        'action delete_user 10 1',
        'action wpmu_delete_user 10 1',
        'action remove_user_from_blog 10 1',
        'action add_user_to_blog 10 3',
        'action set_user_role 10 3',
        'action shutdown 0 1',
        'action add_user_role 10 2',
        'action remove_user_role 10 2',
        'action grant_super_admin 10 1',
        'action revoke_super_admin 10 1',
        'action wp_create_application_password 10 2',
        'action wp_delete_application_password 10 2',
        'action activated_plugin 10 1',
        'action deactivated_plugin 10 1',
        'action deleted_plugin 10 2',
        'action switch_theme 10 3',
        'action deleted_theme 10 2',
        'action upgrader_process_complete 10 2',
        'action updated_option 10 3',
        'action pollora/hornet/scan_completed 10 0',
    ]);
});

it('attaches the journal of the second factor, before and after the metadata is written', function (): void {
    $journal = new InMemoryJournal;
    $module = new TwoFactorJournalModule(new JournalRecorder($journal, new FakeRequestContext, new FakeClock), new FloodGuard(new InMemoryWindowCounter), static fn (): string => '');
    $rows = array_map(static fn (array $row): string => implode(' ', $row), MutationWordPressHooks::of($module)->table);

    expect($rows)->toBe([
        'action two_factor_user_authenticated 10 2',
        'action two_factor_user_revalidated 10 2',
        'action wp_login_failed 10 2',
        'action add_user_meta 10 2',
        'action update_user_meta 10 3',
        'action delete_user_meta 10 3',
        'action added_user_meta 10 3',
        'action updated_user_meta 10 3',
        'action deleted_user_meta 10 3',
        'action delete_user 10 1',
        'action wpmu_delete_user 10 1',
    ]);
});

it('attaches the privacy tools, the dates of login, the passwords and the sessions', function (): void {
    expect(MutationWordPressHooks::of(new JournalPrivacy(new InMemoryJournal, 30))->table)->toBe([
        ['filter', 'wp_privacy_personal_data_exporters', 10, 1],
        ['filter', 'wp_privacy_personal_data_erasers', 10, 1],
        ['action', 'admin_init', 10, 1],
    ])->and(MutationWordPressHooks::of(new LoginDatesModule(new LoginDates))->table)->toBe([
        ['action', 'wp_login', 10, 2],
        ['action', 'admin_init', 10, 1],
        ['action', 'admin_init', 10, 1],
        ['filter', 'wp_privacy_personal_data_exporters', 10, 1],
        ['filter', 'wp_privacy_personal_data_erasers', 10, 1],
    ])->and(MutationWordPressHooks::of(new PasswordModule(new BreachCheck(new FakeHttpClient), 1))->table)->toBe([
        ['action', 'user_profile_update_errors', 10, 3],
        ['action', 'validate_password_reset', 10, 2],
    ])->and(MutationWordPressHooks::of(new SessionModule(60, 120))->table)->toBe([
        ['filter', 'auth_cookie_expiration', 10, 3],
    ])->and(MutationWordPressHooks::of(new DroppedSiteModule)->table)->toBe([
        ['filter', 'wpmu_drop_tables', 10, 2],
    ])->and(MutationWordPressHooks::of(new ErrorDisplayModule(new ErrorDisplayNote))->table)->toBe([
        ['action', 'admin_init', 10, 1],
    ]);
});

it('attaches the cron jobs of the scan and of the notifications', function (): void {
    $job = new ScanJob(static fn (): null => null, new ArrayLock, new InMemoryScanStateStore, new FakeClock, static fn (): array => []);

    expect(MutationWordPressHooks::of(new ScanModule($job, 'off'))->table)->toBe([
        ['action', 'init', 10, 1],
        ['action', 'pollora/hornet/scheduled_scan', 10, 1],
        ['action', 'pollora/hornet/scan_now', 10, 1],
        ['action', 'pollora/hornet/continue_scan', 10, 1],
    ])->and(MutationWordPressHooks::of(new NotificationModule(static fn (): never => throw new LogicException, 'off'))->table)->toBe([
        ['action', 'init', 10, 1],
        ['action', 'pollora/hornet/scheduled_audit', 10, 1],
        ['action', 'pollora/hornet/retry_notifications', 10, 1],
    ]);
});

it('attaches the screen, its routes and the Site Health tests', function (): void {
    $admin = new AdminSite;
    $settings = Settings::fromArray([]);
    $information = new SiteHealthInfo($settings, static fn (): never => throw new LogicException, new FakeEnvironment);
    $health = new SiteHealthModule(static fn (): never => throw new LogicException, new FakeEnvironment, null, $information);
    $page = static fn (bool $network): AdminPageModule => new AdminPageModule($network, '/nowhere', static fn (): never => throw new LogicException);

    expect(MutationWordPressHooks::of($page(false))->table)->toBe([
        ['action', 'admin_menu', 10, 1],
        ['action', 'admin_enqueue_scripts', 10, 1],
        ['action', 'admin_init', 10, 1],
    ])->and(MutationWordPressHooks::of($page(true))->table[0])->toBe(['action', 'network_admin_menu', 10, 1])
        ->and(MutationWordPressHooks::of(new AdminRestModule($admin->backend()))->table)->toBe([
            ['action', 'rest_api_init', 10, 1],
            ['filter', 'rest_index', 10, 1],
        ])->and(MutationWordPressHooks::of($health)->table)->toBe([
            ['filter', 'site_status_tests', 10, 1],
            ['filter', 'debug_information', 10, 1],
        ])->and(MutationWordPressHooks::of(new SettingsErrorModule('x'))->table)->toBe([
            ['filter', 'site_status_tests', 10, 1],
        ]);
});
