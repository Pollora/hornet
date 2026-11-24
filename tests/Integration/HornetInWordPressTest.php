<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;

afterEach(function (): void {
    remove_all_filters('site_status_tests');
    remove_all_filters('xmlrpc_enabled');
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

it('reads the effective state of XML-RPC, whoever changed it', function (): void {
    $environment = new WordPressEnvironment;

    expect($environment->xmlRpcEnabled())->toBeTrue();

    add_filter('xmlrpc_enabled', '__return_false');

    expect($environment->xmlRpcEnabled())->toBeFalse();
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

it('judges the state a site is really in: XML-RPC turned off by someone else passes', function (): void {
    add_filter('xmlrpc_enabled', '__return_false');

    $finding = (new Auditor(DefaultChecks::all()))->run(new WordPressEnvironment)->find('xml-rpc');

    expect($finding?->outcome)->toBe(Outcome::Pass);
});
