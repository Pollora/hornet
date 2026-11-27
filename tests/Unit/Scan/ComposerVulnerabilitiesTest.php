<?php

declare(strict_types=1);

use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Scan\ComposerVulnerabilitiesTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Scan\Advisory;
use Pollora\Hornet\Domain\Scan\AdvisoryDatabase;
use Pollora\Hornet\Domain\Scan\CachedAdvisoryDatabase;
use Pollora\Hornet\Domain\Scan\ComposerInventory;
use Pollora\Hornet\Domain\Scan\ComposerPackage;
use Pollora\Hornet\Domain\Scan\ComposerPackages;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Scan\InstalledComposerPackages;
use Pollora\Hornet\Infrastructure\Scan\OsvAdvisoryDatabase;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\InMemoryAdvisoryCache;
use Pollora\Hornet\Tests\Fixtures\ScriptedAdvisoryDatabase;

function packages(?ComposerInventory $inventory): ComposerPackages
{
    return new readonly class($inventory) implements ComposerPackages
    {
        public function __construct(private ?ComposerInventory $inventory) {}

        public function inventory(): ?ComposerInventory
        {
            return $this->inventory;
        }
    };
}

/**
 * @param  list<string>  $aliases
 */
function advisory(string $id, Severity $severity = Severity::High, array $aliases = [], ?string $fix = null, string $package = 'guzzlehttp/guzzle', bool $withdrawn = false): Advisory
{
    $events = [['introduced', '0'], ...($fix === null ? [] : [['fixed', $fix]])];

    return new Advisory($id, $aliases, 'A flaw in '.$id, $severity, [['package' => $package, 'events' => $events]], $withdrawn);
}

function composerTask(AdvisoryDatabase $database, ?ComposerInventory $inventory, ?FakeClock $clock = null, ?int $budget = null, ?Closure $fetched = null): TaskResult
{
    return (new ComposerVulnerabilitiesTask($database, packages($inventory), $clock ?? new FakeClock, $fetched ?? static fn (): int => 0, $budget))->run(null, false);
}

/**
 * A project folder with the files given, and its vendor folder.
 *
 * @param  array<string, mixed>  $files  path relative to the project => content, written as JSON
 */
function composerProject(array $files): string
{
    $root = sys_get_temp_dir().'/hornet-composer-'.bin2hex(random_bytes(6));
    mkdir($root.'/vendor/composer', 0777, true);

    foreach ($files as $path => $content) {
        file_put_contents($root.'/'.$path, is_string($content) ? $content : json_encode($content));
    }

    return $root.'/vendor';
}

/**
 * @return array<string, mixed>
 */
function entry(string $name, string $version, ?string $notification = 'https://packagist.org/downloads/', string $dist = 'zip'): array
{
    return ['name' => $name, 'version' => $version, 'dist' => ['type' => $dist, 'url' => 'https://api.github.com/repos/x/zipball/1'], ...($notification === null ? [] : ['notification-url' => $notification])];
}

it('asks only about releases of packages that come from Packagist', function (): void {
    $vendor = composerProject(['vendor/composer/installed.json' => [
        'packages' => [
            entry('guzzlehttp/guzzle', '7.4.0'),
            entry('psr/log', 'v3.0.0'),
            entry('phpunit/phpunit', '11.0.0'),
            entry('acme/private', '1.0.0', null),
            entry('acme/satis', '1.0.0', 'https://repo.acme.test/downloads/'),
            entry('acme/local', '1.0.0', 'https://packagist.org/downloads/', 'path'),
            entry('wpackagist-plugin/akismet', '5.3', null),
            entry('acme/branch', 'dev-main'),
            entry('acme/next', '2.x-dev'),
            ['version' => '1.0'],
        ],
        'dev' => true,
        'dev-package-names' => ['phpunit/phpunit'],
    ]]);

    $inventory = (new InstalledComposerPackages($vendor))->inventory();

    expect($inventory?->packages)->toEqual([new ComposerPackage('guzzlehttp/guzzle', '7.4.0'), new ComposerPackage('psr/log', '3.0.0'), new ComposerPackage('phpunit/phpunit', '11.0.0', true)])
        ->and($inventory?->elsewhere)->toBe(4)
        ->and($inventory?->branches)->toBe(['acme/branch', 'acme/next']);
});

it('reads composer.lock when nothing is installed, without its development packages', function (): void {
    $vendor = composerProject(['composer.lock' => ['packages' => [entry('psr/log', '3.0.0')], 'packages-dev' => [entry('phpunit/phpunit', '11.0.0')]]]);

    expect((new InstalledComposerPackages($vendor))->inventory()?->packages)->toEqual([new ComposerPackage('psr/log', '3.0.0')]);
});

it('has no list when there is none it can read', function (): void {
    expect((new InstalledComposerPackages(null))->inventory())->toBeNull()
        ->and((new InstalledComposerPackages(composerProject([])))->inventory())->toBeNull()
        ->and((new InstalledComposerPackages(composerProject(['vendor/composer/installed.json' => 'not json'])))->inventory())->toBeNull();
});

it('finds the vendor folder of the autoloader that loads the package', function (): void {
    $names = array_map(static fn (ComposerPackage $package): string => $package->name, InstalledComposerPackages::ofProject()->inventory()->packages ?? []);

    expect($names)->toContain('pestphp/pest');
});

it('passes when no package asked about has a known vulnerability, and says what was left out', function (): void {
    $database = new ScriptedAdvisoryDatabase;
    $inventory = new ComposerInventory([new ComposerPackage('psr/log', '3.0.0')], 2, ['acme/branch']);

    $finding = composerTask($database, $inventory)->finding;

    expect($finding->outcome)->toBe(Outcome::Pass)
        ->and($finding->summary)->toBe('No known vulnerability in the 1 Composer package asked about.')
        ->and($finding->details)->toBe(['Installed from a branch, so not checked: acme/branch', '2 packages do not come from Packagist: their names were not sent and they were not checked.'])
        ->and($database->batches)->toBe([['psr/log@3.0.0']]);
});

it('lists the flaws, the worst first, one per flaw however many databases file it', function (): void {
    $database = new ScriptedAdvisoryDatabase(
        ['guzzlehttp/guzzle@7.4.0' => ['GHSA-aaaa', 'DRUPAL-CORE-1', 'GHSA-gone'], 'phpunit/phpunit@9.0.0' => ['GHSA-unit']],
        [
            'GHSA-aaaa' => advisory('GHSA-aaaa', Severity::High, ['CVE-2022-31042'], '7.4.4'),
            'DRUPAL-CORE-1' => advisory('DRUPAL-CORE-1', Severity::Medium, ['GHSA-aaaa'], '7.4.5'),
            'GHSA-gone' => advisory('GHSA-gone', withdrawn: true),
            'GHSA-unit' => advisory('GHSA-unit', Severity::Critical, package: 'phpunit/phpunit'),
        ],
    );

    $finding = composerTask($database, new ComposerInventory([new ComposerPackage('guzzlehttp/guzzle', '7.4.0'), new ComposerPackage('phpunit/phpunit', '9.0.0', true)]))->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::Critical)
        ->and($finding->summary)->toBe('2 known vulnerabilities in the Composer packages of the project.')
        ->and($finding->details)->toBe([
            '[critical] phpunit/phpunit 9.0.0 (development package): A flaw in GHSA-unit (GHSA-unit)',
            '[high] guzzlehttp/guzzle 7.4.0: A flaw in GHSA-aaaa (CVE-2022-31042), fixed in 7.4.5',
        ])
        ->and($finding->fix)->toContain('composer update');
});

it('does not merge two GitHub records whose aliases cross, as two real Guzzle flaws do', function (): void {
    // Read on 2026-11-26: both name the other, and both CVE numbers, because of one Drupal advisory
    $shared = ['CVE-2022-31042', 'CVE-2022-31043', 'DRUPAL-CORE-2022-011'];
    $database = new ScriptedAdvisoryDatabase(
        ['guzzlehttp/guzzle@7.4.0' => ['GHSA-f2wf-25xc-69c9', 'GHSA-w248-ffj2-4v5q']],
        [
            'GHSA-f2wf-25xc-69c9' => advisory('GHSA-f2wf-25xc-69c9', aliases: [...$shared, 'GHSA-w248-ffj2-4v5q'], fix: '7.4.4'),
            'GHSA-w248-ffj2-4v5q' => advisory('GHSA-w248-ffj2-4v5q', aliases: [...$shared, 'GHSA-f2wf-25xc-69c9'], fix: '7.4.4'),
        ],
    );

    $finding = composerTask($database, new ComposerInventory([new ComposerPackage('guzzlehttp/guzzle', '7.4.0')]))->finding;

    expect($finding->details)->toBe([
        '[high] guzzlehttp/guzzle 7.4.0: A flaw in GHSA-f2wf-25xc-69c9 (GHSA-f2wf-25xc-69c9), fixed in 7.4.4',
        '[high] guzzlehttp/guzzle 7.4.0: A flaw in GHSA-w248-ffj2-4v5q (GHSA-w248-ffj2-4v5q), fixed in 7.4.4',
    ]);
});

it('says what it did not get, and never calls that a pass', function (): void {
    $one = new ComposerInventory([new ComposerPackage('psr/log', '3.0.0')]);

    $down = composerTask(new ScriptedAdvisoryDatabase(down: true), $one);
    $none = composerTask(new ScriptedAdvisoryDatabase, null);
    $onlyPrivate = composerTask(new ScriptedAdvisoryDatabase, new ComposerInventory([], 3));
    $empty = composerTask(new ScriptedAdvisoryDatabase, new ComposerInventory([]));

    expect($down->finding->outcome)->toBe(Outcome::Unknown)->and($down->finding->summary)->toBe('api.osv.dev did not answer for 1 package.')->and($down->unfinished)->toBeFalse()
        ->and($none->finding->outcome)->toBe(Outcome::Unknown)
        ->and($onlyPrivate->finding->outcome)->toBe(Outcome::Unknown)->and($onlyPrivate->finding->summary)->toStartWith('No Composer package could be asked about.')
        ->and($empty->finding->outcome)->toBe(Outcome::Pass);
});

it('still reports a flaw whose record cannot be read', function (): void {
    $database = new ScriptedAdvisoryDatabase(['psr/log@3.0.0' => ['GHSA-lost']]);

    $finding = composerTask($database, new ComposerInventory([new ComposerPackage('psr/log', '3.0.0')]))->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::Medium)
        ->and($finding->details)->toBe([
            '[medium] psr/log 3.0.0: a vulnerability the database does not describe (GHSA-lost)',
            'Some records could not be read: their severity and their fix are not known.',
        ]);
});

it('asks by a hundred, stops on its budget, goes on from the cache and ends', function (): void {
    $clock = new FakeClock;
    $list = array_map(static fn (int $n): ComposerPackage => new ComposerPackage('acme/p'.$n, '1.0.0'), range(1, 250));
    $inner = new ScriptedAdvisoryDatabase(onAsk: static fn () => $clock->advance('PT10S'));
    $cache = new InMemoryAdvisoryCache;
    $run = static function () use ($inner, $cache, $clock, $list): TaskResult {
        $database = new CachedAdvisoryDatabase($inner, $cache);

        return composerTask($database, new ComposerInventory($list), $clock, 5, $database->fetched(...));
    };

    $first = $run();
    $second = $run();
    $third = $run();

    expect($first->unfinished)->toBeTrue()->and($first->finding->outcome)->toBe(Outcome::Unknown)
        ->and($second->unfinished)->toBeTrue()
        ->and($third->unfinished)->toBeFalse()->and($third->finding->outcome)->toBe(Outcome::Pass)
        ->and(array_map(count(...), $inner->batches))->toBe([100, 100, 50]);
});

it('keeps which records concern a release six hours, a record a week, and asks again when it changed', function (): void {
    $cache = new InMemoryAdvisoryCache;
    $inner = new ScriptedAdvisoryDatabase(['a/b@1.0' => ['GHSA-aaaa']], ['GHSA-aaaa' => advisory('GHSA-aaaa', fix: '2.0')]);
    $database = new CachedAdvisoryDatabase($inner, $cache);

    $database->affecting([new ComposerPackage('a/b', '1.0')]);
    $database->affecting([new ComposerPackage('a/b', '1.0')]);
    $first = $database->advisory('GHSA-aaaa', 'm1');
    $again = $database->advisory('GHSA-aaaa', 'm1');
    $database->advisory('GHSA-aaaa', 'm2');

    expect($inner->batches)->toHaveCount(1)->and($inner->read)->toHaveCount(2)->and($again)->toEqual($first)
        ->and($cache->kept)->toBe(['match:a/b:1.0' => 21600, 'record:GHSA-aaaa:m1' => 604800, 'record:GHSA-aaaa:m2' => 604800]);
});

it('finds where to update to in the stretch the release is in', function (string $version, ?string $fixed): void {
    $advisory = new Advisory('GHSA-aaaa', [], '', Severity::High, [
        ['package' => 'a/b', 'events' => [['introduced', '4.0.0'], ['fixed', '6.5.7'], ['introduced', '7.0.0'], ['fixed', '7.4.4']]],
        ['package' => 'a/b', 'events' => [['introduced', '8.0.0'], ['last_affected', '8.2.0']]],
    ]);

    expect($advisory->fixedFor('a/b', $version))->toBe($fixed)->and($advisory->fixedFor('other/one', $version))->toBeNull();
})->with([
    ['5.0.0', '6.5.7'], ['7.0.0', '7.4.4'], ['7.4.4', null], ['6.9.0', null], ['3.0.0', null], ['8.1.0', null],
]);

it('speaks OSV: one batch for the packages, in order, then the pages that are left', function (): void {
    $http = new FakeHttpClient;
    $http->answers = [
        new HttpResponse(200, null, '{"results":[{"vulns":[{"id":"GHSA-f2wf-25xc-69c9","modified":"2025-12-10T23:40:57Z"}],"next_page_token":"t1"},{}]}'),
        new HttpResponse(200, null, '{"results":[{"vulns":[{"id":"GHSA-25mq-v84q-4j7r","modified":"2024-02-16T08:21:48Z"}]}]}'),
    ];

    $found = (new OsvAdvisoryDatabase($http))->affecting([new ComposerPackage('guzzlehttp/guzzle', '7.4.0'), new ComposerPackage('psr/log', '3.0.0')]);

    expect($found)->toBe([['GHSA-f2wf-25xc-69c9' => '2025-12-10T23:40:57Z', 'GHSA-25mq-v84q-4j7r' => '2024-02-16T08:21:48Z'], []])
        ->and($http->posts[0]['url'])->toBe('https://api.osv.dev/v1/querybatch')
        ->and(json_decode($http->posts[0]['body'], true))->toBe(['queries' => [
            ['package' => ['ecosystem' => 'Packagist', 'name' => 'guzzlehttp/guzzle'], 'version' => '7.4.0'],
            ['package' => ['ecosystem' => 'Packagist', 'name' => 'psr/log'], 'version' => '3.0.0'],
        ]])
        ->and(json_decode($http->posts[1]['body'], true)['queries'])->toBe([['package' => ['ecosystem' => 'Packagist', 'name' => 'guzzlehttp/guzzle'], 'version' => '7.4.0', 'page_token' => 't1']])
        // The answer of a project is far larger than what a notification keeps
        ->and($http->limits[0]['maxBytes'])->toBeGreaterThan(1024 * 1024);
});

it('takes an answer to the batch that does not fit for no answer', function (int $status, string $body): void {
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse($status, null, $body)];

    expect(fn () => (new OsvAdvisoryDatabase($http))->affecting([new ComposerPackage('a/b', '1.0')]))->toThrow(TransportException::class);
})->with([
    'an error' => [500, '{}'],
    'cut' => [200, '{"results":[{"vul'],
    'fewer answers' => [200, '{"results":[]}'],
    'no results' => [200, '{}'],
    'a result that is not one' => [200, '{"results":["x"]}'],
    'an id that is a path' => [200, '{"results":[{"vulns":[{"id":"../x"}]}]}'],
]);

it('does not read pages for ever', function (): void {
    $http = new FakeHttpClient;
    $http->answers = array_fill(0, 12, new HttpResponse(200, null, '{"results":[{"next_page_token":"again"}]}'));

    expect(fn () => (new OsvAdvisoryDatabase($http))->affecting([new ComposerPackage('a/b', '1.0')]))->toThrow(TransportException::class)
        ->and($http->posts)->toHaveCount(10);
});

it('reads a record: severity in words, ranges of Packagist only, summary cleaned', function (): void {
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse(200, null, (string) json_encode([
        'id' => 'GHSA-94pj-82f3-465w',
        'summary' => "Headers\u{202E} sent\nelsewhere",
        'aliases' => ['CVE-2026-67339', '<b>'],
        'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N']],
        'database_specific' => ['severity' => 'MODERATE'],
        'affected' => [
            ['package' => ['ecosystem' => 'Packagist', 'name' => 'guzzlehttp/guzzle'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => 'v7.14.2']]]]],
            ['package' => ['ecosystem' => 'Debian', 'name' => 'guzzle'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0']]]]],
        ],
    ]))];

    $record = (new OsvAdvisoryDatabase($http))->advisory('GHSA-94pj-82f3-465w', 'm');

    expect($record->severity)->toBe(Severity::Medium)->and($record->aliases)->toBe(['CVE-2026-67339'])
        ->and($record->summary)->not->toContain("\n")->not->toContain("\u{202E}")
        ->and($record->ranges)->toBe([['package' => 'guzzlehttp/guzzle', 'events' => [['introduced', '0'], ['fixed', '7.14.2']]]])
        ->and($record->withdrawn)->toBeFalse()
        ->and($http->gets[0]['url'])->toBe('https://api.osv.dev/v1/vulns/GHSA-94pj-82f3-465w')
        ->and(Advisory::fromArray($record->toArray()))->toEqual($record);
});

it('says how bad a record is, and when it was taken back', function (?string $word, Severity $severity): void {
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse(200, null, (string) json_encode(['id' => 'GHSA-aaaa', 'withdrawn' => '2026-01-01T00:00:00Z', ...($word === null ? [] : ['database_specific' => ['severity' => $word]])]))];

    $record = (new OsvAdvisoryDatabase($http))->advisory('GHSA-aaaa', 'm');

    expect($record->severity)->toBe($severity)->and($record->withdrawn)->toBeTrue();
})->with([['CRITICAL', Severity::Critical], ['HIGH', Severity::High], ['MODERATE', Severity::Medium], ['LOW', Severity::Low], [null, Severity::Medium]]);

it('does not take another record, or none, for the one asked', function (int $status, string $body): void {
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse($status, null, $body)];

    expect(fn () => (new OsvAdvisoryDatabase($http))->advisory('GHSA-aaaa', 'm'))->toThrow(TransportException::class);
})->with([[404, '{"code":5}'], [200, '{"id":"GHSA-bbbb"}'], [200, 'x']]);

it('looks at the packages only when both switches are on', function (array $config, bool $on): void {
    $settings = Settings::fromArray(['vulnerabilities' => $config], new ArraySecrets);
    $ids = array_map(static fn (Check $check): string => $check->id(), DefaultChecks::all($settings->vulnerabilities->enabled, null, $settings->vulnerabilities->auditsPackages()));

    expect($settings->vulnerabilities->auditsPackages())->toBe($on)->and(in_array('composer-vulnerabilities', $ids, true))->toBe($on);
})->with([
    'nothing' => [[], false],
    'plugins and themes only' => [['enabled' => true], false],
    'packages without the first yes' => [['composer' => true], false],
    'both' => [['enabled' => true, 'composer' => true], true],
]);

it('refuses a switch that is not true or false', function (): void {
    expect(fn () => Settings::fromArray(['vulnerabilities' => ['composer' => 'yes']], new ArraySecrets))->toThrow(InvalidSettingsException::class, 'vulnerabilities.composer');
});
