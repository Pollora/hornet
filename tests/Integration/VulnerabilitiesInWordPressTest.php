<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Scan\KnownVulnerabilitiesTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Scan\CachedVulnerabilityFeed;
use Pollora\Hornet\Domain\Scan\Software;
use Pollora\Hornet\Domain\Scan\Vulnerability;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\ScannerFactory;
use Pollora\Hornet\Infrastructure\WordPress\TransientVulnerabilityCache;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WordPress\WpPluginInventory;
use Pollora\Hornet\Infrastructure\WordPress\WpThemeInventory;
use Pollora\Hornet\Infrastructure\WordPress\WpVulnerabilityFeed;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;

afterEach(function (): void {
    foreach (['plugin:akismet:5.3', 'plugin:old:1.0'] as $key) {
        delete_site_transient('hornet_vuln_'.md5($key));
    }
});

it('keeps what the feed said in a transient, and reads it back whole', function (): void {
    $cache = new TransientVulnerabilityCache;
    $items = [new Vulnerability('CVE-2026-0001', 'A flaw', Severity::High, '2.0', false, 'https://www.cve.org/x')];

    expect($cache->get('plugin:akismet:5.3'))->toBeNull();

    $cache->put('plugin:akismet:5.3', 1795600000, $items);

    expect($cache->get('plugin:akismet:5.3'))->toEqual(['at' => 1795600000, 'items' => $items]);

    set_site_transient('hornet_vuln_'.md5('plugin:old:1.0'), 'not json');

    expect($cache->get('plugin:old:1.0'))->toBeNull();

    set_site_transient('hornet_vuln_'.md5('plugin:old:1.0'), '{"at":"x","items":[]}');

    expect($cache->get('plugin:old:1.0'))->toBeNull();
});

it('asks the real plugins of the install, through the cache, and finds what the feed says', function (): void {
    $http = new FakeHttpClient;
    $http->answers = array_fill(0, 40, new HttpResponse(200, null, (string) json_encode(['error' => 0, 'data' => ['vulnerability' => null]])));
    $feed = new CachedVulnerabilityFeed(new WpVulnerabilityFeed($http), new TransientVulnerabilityCache, new SystemClock);
    $task = new KnownVulnerabilitiesTask($feed, new WpPluginInventory, new WpThemeInventory, wp_get_wp_version(), new SystemClock, $feed->staleAnswers(...));

    $first = $task->run(null, false)->finding;
    $asked = count($http->gets);
    $second = $task->run(null, false)->finding;

    expect($first->outcome)->toBe(Outcome::Pass)->and($asked)->toBeGreaterThan(2)
        ->and(array_map(static fn (array $get): string => $get['url'], $http->gets))->toContain('https://www.wpvulnerability.net/plugin/two-factor/')
        // The second run is answered by the cache: not one more request
        ->and(count($http->gets))->toBe($asked)->and($second->outcome)->toBe(Outcome::Pass);

    foreach ($http->gets as $get) {
        expect($get['url'])->not->toContain(parse_url(home_url(), PHP_URL_HOST));
    }
});

it('puts the vulnerability scan in the scanner only when the owner opted in', function (): void {
    $off = ScannerFactory::forWordPress(Settings::fromArray([]));
    $on = ScannerFactory::forWordPress(Settings::fromArray(['vulnerabilities' => ['enabled' => true]]));

    expect($off?->tasks())->not->toContain('vulnerabilities')->and($on?->tasks())->toContain('vulnerabilities');
});

it('has the check of the vulnerabilities in the audit only when the owner opted in', function (): void {
    $environment = new WordPressEnvironment;

    expect((new Auditor(DefaultChecks::all()))->run($environment)->find('vulnerabilities'))->toBeNull()
        ->and((new Auditor(DefaultChecks::all(true)))->run($environment)->find('vulnerabilities')?->outcome)->toBe(Outcome::Unknown);
});

it('is not asked about software it does not know the version of', function (): void {
    $http = new FakeHttpClient;

    expect((new WpVulnerabilityFeed($http))->of(Software::Plugin, 'akismet', ''))->toBe([])->and($http->gets)->toBe([]);
});
