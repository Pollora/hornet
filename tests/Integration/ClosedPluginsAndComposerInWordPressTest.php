<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\AbandonedPluginsTask;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\CachedPluginDirectory;
use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Scan\InstalledComposerPackages;
use Pollora\Hornet\Infrastructure\WordPress\AuditorFactory;
use Pollora\Hornet\Infrastructure\WordPress\ScannerFactory;
use Pollora\Hornet\Infrastructure\WordPress\TransientAdvisoryCache;
use Pollora\Hornet\Infrastructure\WordPress\TransientPluginListingCache;
use Pollora\Hornet\Infrastructure\WordPress\WpHttpClient;
use Pollora\Hornet\Infrastructure\WordPress\WpPluginDirectory;
use Pollora\Hornet\Infrastructure\WordPress\WpPluginInventory;
use Pollora\Hornet\Tests\Fixtures\InMemoryPluginListingCache;
use Pollora\Hornet\Tests\Fixtures\RoutedHttpClient;

afterEach(function (): void {
    delete_site_transient('hornet_wporg_'.md5('two-factor'));
    delete_site_transient('hornet_osv_'.md5('match:a/b:1.0'));
    remove_all_filters('pre_http_request');
});

it('keeps what the directory and the database said in transients, and reads it back', function (): void {
    $listings = new TransientPluginListingCache;
    $advisories = new TransientAdvisoryCache;
    $listing = PluginListing::closed('Two Factor', '2025-01-01', 'unused');

    expect($listings->get('two-factor'))->toBeNull()->and($advisories->get('match:a/b:1.0'))->toBeNull();

    $listings->put('two-factor', 1795600000, $listing);
    $advisories->put('match:a/b:1.0', ['GHSA-aaaa' => 'm'], 60);

    expect($listings->get('two-factor'))->toEqual(['at' => 1795600000, 'listing' => $listing])
        ->and($advisories->get('match:a/b:1.0'))->toBe(['GHSA-aaaa' => 'm']);

    set_site_transient('hornet_wporg_'.md5('two-factor'), 'not json');

    expect($listings->get('two-factor'))->toBeNull();
});

it('reads the name and the Update URI of the installed plugins', function (): void {
    $found = array_values(array_filter((new WpPluginInventory)->plugins(), static fn ($plugin): bool => $plugin->slug === 'two-factor'));

    expect($found)->toHaveCount(1)->and($found[0]->name)->toBe('Two Factor')->and($found[0]->updatedElsewhere())->toBeFalse();
});

it('asks the directory about the folder names of the real install, and nothing more', function (): void {
    $http = new RoutedHttpClient(fallback: new HttpResponse(404, null, '{"error":"Plugin not found."}'));
    $directory = new CachedPluginDirectory(new WpPluginDirectory($http), new InMemoryPluginListingCache, new SystemClock);

    $finding = (new AbandonedPluginsTask($directory, new WpPluginInventory, new SystemClock, $directory->fetched(...)))->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Pass)->and($http->asked)->not->toBe([]);

    foreach ($http->asked as $address) {
        expect($address)->toStartWith('https://api.wordpress.org/plugins/info/1.2/')
            ->not->toContain((string) parse_url(home_url(), PHP_URL_HOST))->not->toContain('version=');
    }
});

it('reads a POST answer as far as it is told to, through the HTTP API', function (): void {
    add_filter('pre_http_request', static fn (): array => ['headers' => [], 'body' => str_repeat('a', 20000), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]);

    $http = new WpHttpClient;

    expect(strlen($http->post(Secret::of('https://api.osv.dev/v1/querybatch'), [], '{}')->body))->toBe(4096)
        ->and(strlen($http->post(Secret::of('https://api.osv.dev/v1/querybatch'), [], '{}', false, 1024 * 1024)->body))->toBe(20000);
});

it('has the Composer scan and its check only with both switches on', function (array $vulnerabilities, bool $on): void {
    $settings = Settings::fromArray(['vulnerabilities' => $vulnerabilities]);
    $checks = array_map(static fn (Check $check): string => $check->id(), AuditorFactory::checks($settings));
    $tasks = ScannerFactory::forWordPress($settings)?->tasks() ?? [];

    expect(in_array('composer-vulnerabilities', $tasks, true))->toBe($on)
        ->and(in_array('composer-vulnerabilities', $checks, true))->toBe($on)
        ->and($tasks)->toContain('abandoned-plugins')->and($checks)->toContain('abandoned-plugins');
})->with([
    'off' => [[], false],
    'plugins and themes only' => [['enabled' => true], false],
    'packages without the first switch' => [['composer' => true], false],
    'both' => [['enabled' => true, 'composer' => true], true],
]);

it('reads the packages of the project whose autoloader loads Hornet', function (): void {
    expect(InstalledComposerPackages::ofProject()->inventory()?->packages)->not->toBeEmpty();
});
