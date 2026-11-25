<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Login\TransientAttemptStore;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\HardeningModule;
use Pollora\Hornet\Infrastructure\WordPress\ThrottleProblemNote;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;

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
