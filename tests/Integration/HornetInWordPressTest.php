<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Login\TransientAttemptStore;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\HardeningModule;
use Pollora\Hornet\Infrastructure\WordPress\ThrottleProblemNote;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorEnrollment;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;
use Pollora\Hornet\Infrastructure\WordPress\WpHttpClient;

afterEach(function (): void {
    remove_all_filters('site_status_tests');
    remove_all_filters('xmlrpc_enabled');
    remove_all_filters('xmlrpc_methods');
});

it('has loaded WordPress', function (): void {
    expect(function_exists('add_action'))->toBeTrue()
        ->and(defined('ABSPATH'))->toBeTrue()
        ->and(is_blog_installed())->toBeTrue();
});

it('reads the facts of the running site', function (): void {
    $environment = new WordPressEnvironment;

    expect($environment->phpVersion())->toBe(PHP_VERSION)
        ->and($environment->wordpressVersion())->toBe($GLOBALS['wp_version'])
        ->and($environment->environmentType())->toBeIn(['production', 'staging', 'development', 'local'])
        ->and($environment->isDefined('ABSPATH'))->toBeTrue()
        ->and($environment->isDefined('HORNET_NOT_A_CONSTANT'))->toBeFalse()
        ->and($environment->constant('HORNET_NOT_A_CONSTANT'))->toBeNull()
        ->and($environment->usesHttps())->toBe(str_starts_with(home_url(), 'https://'))
        ->and($environment->userExists('admin'))->toBeTrue()
        ->and($environment->userExists('nobody-has-this-login'))->toBeFalse()
        ->and($environment->isActive(Component::Portcullis))->toBeFalse();
});

it('finds wp-config.php and reads its permission bits', function (): void {
    $environment = new WordPressEnvironment;
    $file = $environment->configFile();

    expect($file)->not->toBeNull()->and(basename((string) $file))->toBe('wp-config.php')
        ->and($environment->permissions((string) $file))->toBeInt()
        ->and($environment->permissions('/no/such/file'))->toBeNull();
});

it('reads what XML-RPC answers, whoever changed it: open, logins refused, or closed', function (): void {
    $environment = new WordPressEnvironment;

    expect($environment->xmlRpcState())->toBe(XmlRpcState::Open);

    add_filter('xmlrpc_enabled', '__return_false');

    // Refusing the logins leaves the pingbacks answering: that is not closed
    expect($environment->xmlRpcState())->toBe(XmlRpcState::LoginDisabled);

    add_filter('xmlrpc_methods', '__return_empty_array');

    expect($environment->xmlRpcState())->toBe(XmlRpcState::Closed);

    remove_all_filters('xmlrpc_methods');
});

it('reads whether the editor is refused, whoever refused it', function (): void {
    $environment = new WordPressEnvironment;

    expect($environment->fileEditingBlocked())->toBeFalse();

    add_filter('map_meta_cap', [HardeningModule::class, 'refuseFileEditing'], 10, 2);

    expect($environment->fileEditingBlocked())->toBeTrue();

    remove_all_filters('map_meta_cap');
});

it('asks the REST API for the users as a visitor would, and puts the current user back', function (): void {
    $environment = new WordPressEnvironment;
    wp_set_current_user(1);

    expect($environment->usersListedToVisitors())->toBeTrue()->and(get_current_user_id())->toBe(1);

    add_filter('rest_endpoints', [HardeningModule::class, 'withoutUserRoutes']);
    // The filter spares a logged-in user, so a visitor is what the question needs
    $hidden = $environment->usersListedToVisitors();
    remove_all_filters('rest_endpoints');
    wp_set_current_user(0);

    expect($hidden)->toBeFalse();
});

it('finds no core update to report on a fresh site, and reads the stored answer when there is one', function (): void {
    $environment = new WordPressEnvironment;
    $previous = get_site_transient('update_core');

    set_site_transient('update_core', (object) ['updates' => [(object) ['response' => 'upgrade', 'current' => '99.0.0']]]);
    expect($environment->availableCoreUpdate())->toBe('99.0.0');

    set_site_transient('update_core', (object) ['updates' => [(object) ['response' => 'latest', 'current' => '7.1.5']]]);
    expect($environment->availableCoreUpdate())->toBeNull();

    $previous === false ? delete_site_transient('update_core') : set_site_transient('update_core', $previous);
});

it('runs every check against the real site without one of them failing to run', function (): void {
    $report = (new Auditor(DefaultChecks::all()))->run(new WordPressEnvironment);

    expect($report->findings)->toHaveCount(count(DefaultChecks::all()));

    foreach ($report->findings as $finding) {
        expect($finding->summary)->not->toContain('could not run');
    }
});

it('never lets a secret of the site reach a finding', function (): void {
    $report = (new Auditor(DefaultChecks::all()))->run(new WordPressEnvironment);
    $json = (string) json_encode(array_map(static fn ($finding): array => $finding->toArray(), $report->findings));

    foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'DB_PASSWORD'] as $name) {
        $value = (string) constant($name);

        if ($value !== '') {
            expect($json)->not->toContain($value);
        }
    }
});

it('adds its tests to Site Health, and each one answers in the shape WordPress reads', function (): void {
    Bootstrap::boot(Settings::fromArray([]), new WordPressHooks, new WordPressEnvironment);

    $tests = WP_Site_Health::get_tests();

    expect($tests['direct'])->toHaveKey('hornet_salts')->toHaveKey('hornet_xml_rpc');

    foreach ($tests['direct'] as $name => $test) {
        if (! str_starts_with($name, 'hornet_')) {
            continue;
        }

        $result = ($test['test'])();

        expect($result['status'])->toBeIn(['good', 'recommended', 'critical'])
            ->and($result['label'])->toBeString()->not->toBe('')
            ->and($result['badge'])->toHaveKeys(['label', 'color'])
            ->and($result['description'])->toBeString()
            ->and($result['test'])->toBe($name);
    }
});

it('adds nothing to Site Health when it is switched off', function (): void {
    Bootstrap::boot(Settings::fromArray(['enabled' => false]), new WordPressHooks, new WordPressEnvironment);

    expect(array_keys(WP_Site_Health::get_tests()['direct']))->not->toContain('hornet_salts');
});

it('keeps the site running and shows one critical test when its settings are wrong', function (): void {
    Bootstrap::boot(null, new WordPressHooks, new WordPressEnvironment, 'Unknown Hornet setting "enable".');

    $result = (WP_Site_Health::get_tests()['direct']['hornet_settings']['test'])();

    expect($result['status'])->toBe('critical');
});

it('judges the state a site is really in: XML-RPC closed by someone else passes', function (): void {
    add_filter('xmlrpc_methods', '__return_empty_array');

    $finding = (new Auditor(DefaultChecks::all()))->run(new WordPressEnvironment)->find('xml-rpc');

    expect($finding?->outcome)->toBe(Outcome::Pass);
});

describe('login attempts', function (): void {
    afterEach(function (): void {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%hornet\\_lg\\_%' OR option_name LIKE 'hornet\\_lockouts' OR option_name LIKE 'hornet\\_throttle\\_problem'");
        wp_cache_flush();
    });

    it('keeps the failures of an address and forgets them', function (): void {
        $store = new TransientAttemptStore;
        $key = str_repeat('a', 64);

        expect($store->get($key)->failures)->toBe(0);

        $store->put($key, new Attempts(2, time(), 0, 0), 900);

        expect($store->get($key)->failures)->toBe(2);

        $store->forget($key);

        expect($store->get($key)->failures)->toBe(0);
    });

    it('lists the lockouts so that all of them can be lifted, and counts the locked ones', function (): void {
        $store = new TransientAttemptStore;
        $store->put(str_repeat('a', 64), new Attempts(0, 0, time() + 900, 1), 900);
        $store->put(str_repeat('b', 64), new Attempts(0, 0, time() + 900, 1), 900);
        $store->put(str_repeat('c', 64), new Attempts(1, time(), 0, 0), 900);

        expect($store->forgetAll())->toBe(2)->and($store->get(str_repeat('a', 64))->lockedUntil)->toBe(0)->and(get_option('hornet_lockouts'))->toBeFalse();
    });

    it('does not keep an index entry for an address that is no longer locked', function (): void {
        $store = new TransientAttemptStore;
        $store->put(str_repeat('a', 64), new Attempts(0, 0, time() + 900, 1), 900);
        $store->forget(str_repeat('a', 64));

        expect(get_option('hornet_lockouts'))->toBeFalse();
    });

    it('ignores a damaged index', function (): void {
        update_option('hornet_lockouts', ['not-a-hash' => 1, str_repeat('a', 64) => 'x', 5 => 5], false);

        expect((new TransientAttemptStore)->forgetAll())->toBe(0);
    });

    it('writes a note about a request it could not trust, once an hour, and lets it age out', function (): void {
        $note = new ThrottleProblemNote;

        expect($note->read())->toBeNull();

        $note->record('shared');
        $first = get_option('hornet_throttle_problem');
        $note->record('shared');

        expect($note->read())->toBe('shared')->and(get_option('hornet_throttle_problem'))->toBe($first);

        $note->record('forwarded-untrusted');

        expect($note->read())->toBe('forwarded-untrusted');

        update_option('hornet_throttle_problem', ['reason' => 'shared', 'at' => time() - 700000], false);

        expect($note->read())->toBeNull();

        update_option('hornet_throttle_problem', ['reason' => 'something else', 'at' => time()], false);

        expect($note->read())->toBeNull();
    });

    it('turns a note into a finding of the audit', function (): void {
        (new ThrottleProblemNote)->record('shared');
        $settings = Settings::fromArray(['login' => ['throttle' => true]]);
        $finding = (new Auditor(DefaultChecks::all()))->run(new WordPressEnvironment($settings))->find('login-protection');

        expect($finding?->outcome)->toBe(Outcome::Fail)->and($finding?->severity)->toBe(Severity::High);
    });

    it('knows the throttle is on only when the settings say so', function (): void {
        expect((new WordPressEnvironment)->isActive(Component::HornetThrottle))->toBeFalse()
            ->and((new WordPressEnvironment(Settings::fromArray([])))->isActive(Component::HornetThrottle))->toBeFalse()
            ->and((new WordPressEnvironment(Settings::fromArray(['login' => ['throttle' => true]])))->isActive(Component::HornetThrottle))->toBeTrue();
    });
});

describe('accounts', function (): void {
    afterEach(function (): void {
        delete_user_meta(1, '_two_factor_enabled_providers');
        delete_user_meta(1, '_two_factor_provider');
        remove_all_filters('two_factor_is_required_for_user');
        remove_all_filters('pre_http_request');
        remove_all_filters('auth_cookie_expiration');
        remove_all_filters('user_profile_update_errors');
        remove_all_filters('validate_password_reset');
    });

    it('has the Two-Factor plugin to test against', function (): void {
        expect(class_exists('Two_Factor_Core'))->toBeTrue('The Two-Factor plugin must be installed for these tests: wp plugin install two-factor');
    });

    it('reads enrollment from the methods a user has enabled', function (): void {
        $enrollment = new TwoFactorEnrollment;

        expect($enrollment->available())->toBeTrue()->and($enrollment->enrolled(1))->toBeFalse();

        Two_Factor_Core::enable_provider_for_user(1, 'Two_Factor_Email');

        expect($enrollment->enrolled(1))->toBeTrue();
    });

    it('is not fooled by the filter that other plugins use to skip two-factor', function (): void {
        $enrollment = new TwoFactorEnrollment;
        Two_Factor_Core::enable_provider_for_user(1, 'Two_Factor_Email');
        add_filter('two_factor_is_required_for_user', '__return_false');

        // The plugin's own answer says "no two-factor here"; the enrollment is still a fact
        expect(Two_Factor_Core::is_user_using_two_factor(1))->toBeFalse()->and($enrollment->enrolled(1))->toBeTrue();
    });

    it('says who has no second factor among the roles it watches', function (): void {
        expect((new WordPressEnvironment)->usersWithoutTwoFactor())->toBe([1]);

        Two_Factor_Core::enable_provider_for_user(1, 'Two_Factor_Email');

        expect((new WordPressEnvironment)->usersWithoutTwoFactor())->toBe([])
            ->and((new WordPressEnvironment(Settings::fromArray(['two_factor' => ['require_roles' => ['editor']]])))->usersWithoutTwoFactor())->toBe([]);
    });

    it('turns that into a finding that names accounts by number', function (): void {
        $finding = (new Auditor(DefaultChecks::all()))->run(new WordPressEnvironment)->find('two-factor-coverage');

        expect($finding?->outcome)->toBe(Outcome::Fail)->and($finding?->details)->toBe(['user #1']);
    });

    it('asks the breach service with a short timeout, a size limit and no redirect, and keeps what it reads below the limit', function (): void {
        $seen = null;
        add_filter('pre_http_request', static function ($pre, array $args, string $url) use (&$seen): array {
            $seen = ['args' => $args, 'url' => $url];

            return ['headers' => [], 'body' => str_repeat('A', 5000), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        }, 10, 3);

        $response = (new WpHttpClient)->get(Secret::of('https://api.pwnedpasswords.com/range/5BAA6'), ['Add-Padding' => 'true'], 100, 3);

        expect($seen['url'])->toBe('https://api.pwnedpasswords.com/range/5BAA6')
            ->and($seen['args']['timeout'])->toBe(3)->and($seen['args']['redirection'])->toBe(0)->and($seen['args']['limit_response_size'])->toBe(100)
            ->and($seen['args']['headers'])->toBe(['Add-Padding' => 'true'])
            ->and($response->status)->toBe(200)->and(strlen($response->body))->toBe(100);
    });

    it('refuses a private address for a GET, and never names it', function (): void {
        try {
            (new WpHttpClient)->get(Secret::of('http://127.0.0.1:9/x?token=TOPSECRET'), [], 100);
            $message = 'no exception';
        } catch (TransportException $error) {
            $message = $error->getMessage();
        }

        expect($message)->toStartWith('The request failed')->not->toContain('TOPSECRET')->not->toContain('127.0.0.1');
    });

    it('refuses a breached password in the profile, through the real hook', function (): void {
        add_filter('pre_http_request', static fn (): array => ['headers' => [], 'body' => "0018A45C4D1DEF81644B54AB7F969B88D65:1\n".substr(strtoupper(sha1('password')), 5).':9999', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]);
        Bootstrap::boot(Settings::fromArray(['passwords' => ['check_breached' => true]]), new WordPressHooks, new WordPressEnvironment);

        $errors = new WP_Error;
        $user = (object) ['user_pass' => 'password'];
        do_action_ref_array('user_profile_update_errors', [&$errors, true, &$user]);

        expect($errors->get_error_code())->toBe('hornet_breached_password');

        $fine = new WP_Error;
        $other = (object) ['user_pass' => 'a-long-unique-passphrase-9f3'];
        add_filter('pre_http_request', static fn (): array => ['headers' => [], 'body' => '0018A45C4D1DEF81644B54AB7F969B88D65:1', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null], 20);
        do_action_ref_array('user_profile_update_errors', [&$fine, true, &$other]);

        expect($fine->has_errors())->toBeFalse();
    });

    it('lets a password through when the service is down', function (): void {
        add_filter('pre_http_request', static fn () => new WP_Error('http_request_failed', 'down'));
        Bootstrap::boot(Settings::fromArray(['passwords' => ['check_breached' => true]]), new WordPressHooks, new WordPressEnvironment);

        $errors = new WP_Error;
        $user = (object) ['user_pass' => 'password'];
        do_action_ref_array('user_profile_update_errors', [&$errors, true, &$user]);

        expect($errors->has_errors())->toBeFalse();
    });

    it('shortens a login through the real filter', function (): void {
        Bootstrap::boot(Settings::fromArray(['session' => ['lifetime' => 3600, 'remember_lifetime' => 7200]]), new WordPressHooks, new WordPressEnvironment);

        expect(apply_filters('auth_cookie_expiration', 172800, 1, false))->toBe(3600)->and(apply_filters('auth_cookie_expiration', 1209600, 1, true))->toBe(7200);
    });
});

it('reads an open registration and its default role from the site', function (): void {
    $environment = new WordPressEnvironment;
    $before = [get_option('users_can_register'), get_option('default_role')];

    try {
        update_option('users_can_register', 0);
        expect($environment->registrationRole())->toBeNull();

        update_option('users_can_register', 1);
        update_option('default_role', 'administrator');
        $role = $environment->registrationRole();

        // A network gives no role by registration
        is_multisite()
            ? expect($role)->toBeNull()
            : expect($role['role'])->toBe('administrator')->and($role['capabilities'])->toContain('manage_options');
    } finally {
        update_option('users_can_register', $before[0]);
        update_option('default_role', $before[1]);
    }
});

it('reads the application passwords of administrators, and nothing but their dates', function (): void {
    add_filter('wp_is_application_passwords_available', '__return_true');
    $admin = get_user_by('login', 'admin')->ID;
    $reader = wp_insert_user(['user_login' => 'app-pass-reader', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);

    try {
        WP_Application_Passwords::create_new_application_password($admin, ['name' => 'a script of mine']);
        WP_Application_Passwords::create_new_application_password($reader, ['name' => 'not an administrator']);
        $found = (new WordPressEnvironment)->administratorApplicationPasswords();

        expect($found)->toHaveCount(1)
            ->and(array_keys($found[0]))->toBe(['user', 'created', 'last_used'])
            ->and($found[0]['user'])->toBe($admin)
            ->and($found[0]['last_used'])->toBeNull();

        remove_all_filters('wp_is_application_passwords_available');
        add_filter('wp_is_application_passwords_available', '__return_false');

        expect((new WordPressEnvironment)->administratorApplicationPasswords())->toBeNull();
    } finally {
        remove_all_filters('wp_is_application_passwords_available');
        WP_Application_Passwords::delete_all_application_passwords($admin);
        require_once ABSPATH.'wp-admin/includes/user.php';
        wp_delete_user($reader);
    }
});

it('reads the plugin updates WordPress stored, and drops one that was applied since', function (): void {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    $files = ['hornet-behind/hornet-behind.php' => '1.0', 'hornet-done.php' => '2.0', 'hornet-custom/hornet-custom.php' => '1.0'];

    foreach ($files as $file => $version) {
        wp_mkdir_p(dirname(WP_PLUGIN_DIR.'/'.$file));
        file_put_contents(WP_PLUGIN_DIR.'/'.$file, "<?php\n/**\n * Plugin Name: {$file}\n * Version: {$version}\n */\n");
    }

    $before = get_site_transient('update_plugins');
    $active = get_option('active_plugins', []);

    try {
        wp_clean_plugins_cache(false);
        // The filter that asks WordPress.org again must not answer here
        add_filter('pre_site_transient_update_plugins', $stored = static fn (): object => (object) [
            'last_checked' => 1_790_000_000,
            'response' => [
                'hornet-behind/hornet-behind.php' => (object) ['new_version' => '1.1'],
                'hornet-done.php' => (object) ['new_version' => '2.0'],
            ],
            'no_update' => [],
        ]);
        $updates = (new WordPressEnvironment)->pluginUpdates();

        expect($updates['checked'])->toBe(1_790_000_000)
            ->and($updates['waiting'])->toBe([['slug' => 'hornet-behind', 'installed' => '1.0', 'available' => '1.1']])
            ->and($updates['unfollowed'])->toBeGreaterThanOrEqual(1);

        // One of them is active: it is not reported
        update_option('active_plugins', [...(array) $active, 'hornet-done.php']);
        $inactive = (new WordPressEnvironment)->inactivePlugins();

        is_multisite()
            ? expect($inactive)->toBeNull()
            : expect($inactive)->toContain('hornet-behind', 'hornet-custom')->not->toContain('hornet-done');

        remove_filter('pre_site_transient_update_plugins', $stored);
        add_filter('pre_site_transient_update_plugins', '__return_null');
        add_filter('site_transient_update_plugins', '__return_false');

        expect((new WordPressEnvironment)->pluginUpdates())->toBeNull();
    } finally {
        remove_all_filters('pre_site_transient_update_plugins');
        remove_all_filters('site_transient_update_plugins');
        update_option('active_plugins', $active);

        foreach (array_keys($files) as $file) {
            unlink(WP_PLUGIN_DIR.'/'.$file);

            if (dirname($file) !== '.') {
                rmdir(WP_PLUGIN_DIR.'/'.dirname($file));
            }
        }

        wp_clean_plugins_cache(false);
        expect(get_site_transient('update_plugins'))->toEqual($before);
    }
});

it('keeps the active theme, its parent and one default theme, and reports the others', function (): void {
    $root = get_theme_root();
    $themes = ['hornet-parent' => '', 'hornet-child' => 'hornet-parent', 'hornet-left' => ''];

    foreach ($themes as $slug => $parent) {
        wp_mkdir_p($root.'/'.$slug);
        file_put_contents($root.'/'.$slug.'/style.css', "/*\nTheme Name: {$slug}\nVersion: 1.0\n".($parent === '' ? '' : "Template: {$parent}\n").'*/');
        file_put_contents($root.'/'.$slug.'/index.php', "<?php\n");
    }

    $before = get_stylesheet();

    try {
        wp_clean_themes_cache();
        switch_theme('hornet-child');
        $unused = (new WordPressEnvironment)->unusedThemes();

        is_multisite()
            ? expect($unused)->toBeNull()
            : expect($unused)->toContain('hornet-left')->not->toContain('hornet-child', 'hornet-parent', wp_get_theme(WP_DEFAULT_THEME)->exists() ? WP_DEFAULT_THEME : WP_Theme::get_core_default_theme()->get_stylesheet());

        // A theme written for the site is not looked up
        $settings = Settings::fromArray(['scanner' => ['own_themes' => ['hornet-left']]]);
        add_filter('pre_site_transient_update_themes', static fn (): object => (object) [
            'last_checked' => 1_790_000_000,
            'response' => ['hornet-left' => ['new_version' => '9.0'], 'hornet-parent' => ['new_version' => '1.2']],
            'no_update' => [],
        ]);

        expect((new WordPressEnvironment($settings))->themeUpdates()['waiting'])->toBe([['slug' => 'hornet-parent', 'installed' => '1.0', 'available' => '1.2']]);
    } finally {
        remove_all_filters('pre_site_transient_update_themes');
        switch_theme($before);

        foreach (array_keys($themes) as $slug) {
            unlink($root.'/'.$slug.'/style.css');
            unlink($root.'/'.$slug.'/index.php');
            rmdir($root.'/'.$slug);
        }

        wp_clean_themes_cache();
    }
});
