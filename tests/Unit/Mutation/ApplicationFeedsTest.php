<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\AbandonedPluginsTask;
use Pollora\Hornet\Application\Scan\ComposerVulnerabilitiesTask;
use Pollora\Hornet\Application\Scan\KnownVulnerabilitiesTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\Advisory;
use Pollora\Hornet\Domain\Scan\ComposerInventory;
use Pollora\Hornet\Domain\Scan\ComposerPackage;
use Pollora\Hornet\Domain\Scan\ComposerPackages;
use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\PluginInventory;
use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Domain\Scan\Vulnerability;
use Pollora\Hornet\Tests\Fixtures\ArrayPluginInventory;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\ScriptedAdvisoryDatabase;
use Pollora\Hornet\Tests\Fixtures\ScriptedPluginDirectory;
use Pollora\Hornet\Tests\Fixtures\ScriptedVulnerabilityFeed;

// Known vulnerabilities of the core, plugins and themes

/**
 * @param  array<string, string>  $plugins
 * @param  array<string, string>  $themes
 * @param  list<string>  $stale
 */
function mutAppVulns(ScriptedVulnerabilityFeed $feed, array $plugins = [], array $themes = [], ?int $budget = null, ?FakeClock $clock = null, array $stale = []): TaskResult
{
    return (new KnownVulnerabilitiesTask($feed, new ArrayPluginInventory($plugins), new ArrayPluginInventory($themes), '7.0', $clock ?? new FakeClock, static fn (): array => $stale, $budget))->run(null, false);
}

it('asks the core, every plugin and every theme, and says the names of what did not answer', function (): void {
    $feed = new ScriptedVulnerabilityFeed(['plugin:one:1.0' => new TransportException('x'), 'theme:look:2.0' => new TransportException('x')]);

    $finding = mutAppVulns($feed, ['one' => '1.0', 'two' => '1.1'], ['look' => '2.0'])->finding;

    expect($feed->asked)->toBe(['core::7.0', 'plugin:one:1.0', 'plugin:two:1.1', 'theme:look:2.0'])
        ->and($finding->outcome)->toBe(Outcome::Pass)
        ->and($finding->summary)->toBe('No known vulnerability in the 2 versions that were asked about.')
        ->and($finding->details)->toBe(['Not checked: one 1.0 (plugin), look 2.0 (theme)']);
});

it('names the core by its version in what it says', function (): void {
    $finding = mutAppVulns(new ScriptedVulnerabilityFeed(['core::7.0' => [new Vulnerability('CVE-1', 'Flaw', Severity::Low)]]))->finding;

    expect($finding->details)->toBe(['[low] WordPress 7.0: Flaw (CVE-1)']);
});

it('stops asking once past the budget, not at it, and counts what is left', function (): void {
    $clock = new FakeClock;
    $feed = new ScriptedVulnerabilityFeed(onAsk: static fn () => $clock->advance('PT10S'));

    $finding = mutAppVulns($feed, ['a' => '1', 'b' => '1', 'c' => '1'], budget: 10, clock: $clock)->finding;

    expect($feed->asked)->toHaveCount(2)->and($finding->details)->toBe(['Not checked: 2 more (out of time)']);
});

it('has no limit without a budget', function (): void {
    $clock = new FakeClock;
    $feed = new ScriptedVulnerabilityFeed(onAsk: static fn () => $clock->advance('P1D'));

    mutAppVulns($feed, ['a' => '1', 'b' => '1'], clock: $clock);

    expect($feed->asked)->toHaveCount(3);
});

it('does not know when nothing answers', function (): void {
    expect(mutAppVulns(new ScriptedVulnerabilityFeed(down: true), ['a' => '1'])->finding->outcome)->toBe(Outcome::Unknown);
});

it('says when an old copy answered for a feed that is down', function (): void {
    $finding = mutAppVulns(new ScriptedVulnerabilityFeed, stale: ['one', 'two'])->finding;

    expect($finding->details)->toBe(['From a copy of a few days ago, the feed being down: one, two']);
});

it('sorts the flaws by severity then by name, says how each is fixed, and puts the notes last', function (): void {
    $feed = new ScriptedVulnerabilityFeed([
        'plugin:bbb:1' => [new Vulnerability('CVE-2', 'Second', Severity::High, unfixed: true)],
        'plugin:aaa:1' => [new Vulnerability('CVE-3', 'Third', Severity::High, '2.0'), new Vulnerability('CVE-4', 'Fourth', Severity::Low)],
        'plugin:ccc:1' => [new Vulnerability('CVE-1', 'First', Severity::Critical)],
        'plugin:ddd:1' => new TransportException('x'),
    ]);

    $finding = mutAppVulns($feed, ['bbb' => '1', 'aaa' => '1', 'ccc' => '1', 'ddd' => '1'])->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)
        ->and($finding->severity)->toBe(Severity::Critical)
        ->and($finding->summary)->toBe('4 known vulnerabilities in what the site runs.')
        ->and($finding->details)->toBe([
            '[critical] ccc 1 (plugin): First (CVE-1)',
            '[high] aaa 1 (plugin): Third (CVE-3), fixed in 2.0',
            '[high] bbb 1 (plugin): Second (CVE-2), not fixed',
            '[low] aaa 1 (plugin): Fourth (CVE-4)',
            'Not checked: ddd 1 (plugin)',
        ])
        ->and($finding->fix)->toContain('Hornet changes nothing');
});

it('lists fifteen flaws and counts the rest, and no count when there is none left', function (int $count, int $lines, ?string $last): void {
    $flaws = array_map(static fn (int $n): Vulnerability => new Vulnerability('CVE-'.$n, 'Flaw', Severity::Medium), range(1, $count));

    $finding = mutAppVulns(new ScriptedVulnerabilityFeed(['core::7.0' => $flaws]))->finding;

    expect($finding->details)->toHaveCount($lines)->and($finding->details[$lines - 1] === 'and 1 more')->toBe($last !== null);
})->with(['fifteen' => [15, 15, null], 'sixteen' => [16, 16, 'and 1 more']]);

// Composer packages

function mutAppInventory(?ComposerInventory $inventory): ComposerPackages
{
    return new class($inventory) implements ComposerPackages
    {
        public function __construct(private readonly ?ComposerInventory $inventory) {}

        public function inventory(): ?ComposerInventory
        {
            return $this->inventory;
        }
    };
}

/**
 * @return list<ComposerPackage> packages p/001, p/002... at 1.0.0
 */
function mutAppPackages(int $count): array
{
    return array_map(static fn (int $n): ComposerPackage => new ComposerPackage(sprintf('p/%03d', $n), '1.0.0', false), range(1, $count));
}

/**
 * @param  list<ComposerPackage>  $packages
 * @param  list<string>  $branches
 */
function mutAppComposer(ScriptedAdvisoryDatabase $database, array $packages, int $fetched = 1, ?int $budget = null, ?FakeClock $clock = null, int $elsewhere = 0, array $branches = []): TaskResult
{
    return (new ComposerVulnerabilitiesTask($database, mutAppInventory(new ComposerInventory($packages, $elsewhere, $branches)), $clock ?? new FakeClock, static fn (): int => $fetched, $budget))->run(null, false);
}

function mutAppAdvisory(string $id, Severity $severity = Severity::High, array $aliases = [], string $summary = 'Flaw', array $fixed = [], bool $withdrawn = false): Advisory
{
    $ranges = array_map(static fn (string $version): array => ['package' => 'p/001', 'events' => [['introduced', '0'], ['fixed', $version]]], $fixed);

    return new Advisory($id, $aliases, $summary, $severity, $ranges, $withdrawn);
}

it('asks a hundred packages at a time', function (): void {
    $database = new ScriptedAdvisoryDatabase;

    $finding = mutAppComposer($database, mutAppPackages(101))->finding;

    expect(array_map(count(...), $database->batches))->toBe([100, 1])
        ->and($finding->summary)->toBe('No known vulnerability in the 101 Composer packages asked about.');
});

it('stops past the budget, counts what is left, and asks for a run that goes on only when it moved', function (): void {
    $run = static function (int $fetched): array {
        $clock = new FakeClock;
        $database = new ScriptedAdvisoryDatabase(onAsk: static fn () => $clock->advance('PT10S'));

        return [mutAppComposer($database, mutAppPackages(301), $fetched, 10, $clock), $database];
    };

    [$moved, $database] = $run(1);
    [$stuck] = $run(0);

    expect(array_map(count(...), $database->batches))->toBe([100, 100])
        ->and($moved->finding->outcome)->toBe(Outcome::Unknown)
        ->and($moved->finding->summary)->toBe('Not everything was read in the time allowed.')
        ->and($moved->unfinished)->toBeTrue()
        ->and($stuck->unfinished)->toBeFalse();
});

it('says how many packages the database did not answer for, over every batch', function (): void {
    $finding = mutAppComposer(new ScriptedAdvisoryDatabase(down: true), mutAppPackages(101))->finding;

    expect($finding->outcome)->toBe(Outcome::Unknown)->and($finding->summary)->toBe('api.osv.dev did not answer for 101 packages.');
});

it('tells a flaw whose record cannot be read, as medium, and says so', function (): void {
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['GHSA-aaaa'], 'p/002@1.0.0' => ['GHSA-aaaa']]);

    $result = mutAppComposer($database, mutAppPackages(2));

    expect($result->finding->outcome)->toBe(Outcome::Fail)
        ->and($result->finding->severity)->toBe(Severity::Medium)
        ->and($result->finding->summary)->toBe('2 known vulnerabilities in the Composer packages of the project.')
        ->and($result->finding->details)->toBe([
            '[medium] p/001 1.0.0: a vulnerability the database does not describe (GHSA-aaaa)',
            '[medium] p/002 1.0.0: a vulnerability the database does not describe (GHSA-aaaa)',
            'Some records could not be read: their severity and their fix are not known.',
        ])
        // The record is asked once, whatever the number of packages it concerns
        ->and($database->read)->toBe(['GHSA-aaaa']);
});

it('still tells a flaw it has no time to read', function (): void {
    $clock = new FakeClock;
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['A-1']], ['A-1' => mutAppAdvisory('A-1')], onAsk: static fn () => $clock->advance('PT11S'));

    $result = mutAppComposer($database, mutAppPackages(1), budget: 10, clock: $clock);

    expect($database->read)->toBe([])
        ->and($result->finding->details)->toBe(['[medium] p/001 1.0.0: a vulnerability the database does not describe (A-1)', 'Not everything was read in the time allowed.'])
        ->and($result->unfinished)->toBeTrue();
});

it('leaves out a record the database took back', function (): void {
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['A-1']], ['A-1' => mutAppAdvisory('A-1', withdrawn: true)]);

    expect(mutAppComposer($database, mutAppPackages(1))->finding->outcome)->toBe(Outcome::Pass);
});

it('makes one flaw of records that name each other, whichever names the other, with the worst severity and the highest fix', function (bool $firstNames): void {
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['AAA-1', 'GHSA-bbbb']], [
        'AAA-1' => mutAppAdvisory('AAA-1', Severity::Low, $firstNames ? ['GHSA-bbbb'] : [], fixed: ['1.10.0']),
        'GHSA-bbbb' => mutAppAdvisory('GHSA-bbbb', Severity::Critical, $firstNames ? ['CVE-2026-0001'] : ['AAA-1', 'CVE-2026-0001'], 'Worst', fixed: ['1.9.0']),
    ]);

    $finding = mutAppComposer($database, mutAppPackages(1))->finding;

    expect($finding->details)->toBe(['[critical] p/001 1.0.0: Worst (CVE-2026-0001), fixed in 1.10.0']);
})->with([true, false]);

it('never makes one of two GitHub records, even when their names cross', function (): void {
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['GHSA-aaaa', 'GHSA-bbbb']], [
        'GHSA-aaaa' => mutAppAdvisory('GHSA-aaaa', aliases: ['GHSA-bbbb']),
        'GHSA-bbbb' => mutAppAdvisory('GHSA-bbbb', aliases: ['GHSA-aaaa']),
    ]);

    expect(mutAppComposer($database, mutAppPackages(1))->finding->details)->toHaveCount(2);
});

it('keeps apart records that name nothing in common, and sorts equals by identifier', function (): void {
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['Z-1', 'B-1']], ['Z-1' => mutAppAdvisory('Z-1', summary: 'zed'), 'B-1' => mutAppAdvisory('B-1', summary: 'bee')]);

    expect(mutAppComposer($database, mutAppPackages(1))->finding->details)->toHaveCount(2);

    $merged = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['Z-1', 'B-1']], ['Z-1' => mutAppAdvisory('Z-1', summary: 'zed'), 'B-1' => mutAppAdvisory('B-1', aliases: ['Z-1'], summary: 'bee')]);

    expect(mutAppComposer($merged, mutAppPackages(1))->finding->details)->toBe(['[high] p/001 1.0.0: bee (B-1)']);
});

it('gives the CVE number only when there is just one, and only a whole one', function (array $aliases, string $name): void {
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['A-1']], ['A-1' => mutAppAdvisory('A-1', aliases: $aliases)]);

    expect(mutAppComposer($database, mutAppPackages(1))->finding->details[0])->toEndWith('('.$name.')');
})->with([
    'one' => [['CVE-2026-1234'], 'CVE-2026-1234'],
    'the same twice' => [['CVE-2026-1234', 'CVE-2026-1234'], 'CVE-2026-1234'],
    'two' => [['CVE-2026-1234', 'CVE-2026-1235'], 'A-1'],
    'not whole' => [['xCVE-2026-1234'], 'A-1'],
    'a tail' => [['CVE-2026-1234-x'], 'A-1'],
]);

it('marks a development package', function (): void {
    $database = new ScriptedAdvisoryDatabase(['dev/tool@2.0' => ['A-1']], ['A-1' => mutAppAdvisory('A-1')]);

    expect(mutAppComposer($database, [new ComposerPackage('dev/tool', '2.0', true)])->finding->details)->toBe(['[high] dev/tool 2.0 (development package): Flaw (A-1)']);
});

it('sorts the packages by the worst first then by name, lists fifteen and counts the rest', function (): void {
    $matches = [];

    foreach (range(1, 16) as $n) {
        $matches[sprintf('p/%03d@1.0.0', $n)] = [$n === 16 ? 'BAD-1' : 'LOW-1'];
    }

    $database = new ScriptedAdvisoryDatabase($matches, ['LOW-1' => mutAppAdvisory('LOW-1', Severity::Low), 'BAD-1' => mutAppAdvisory('BAD-1', Severity::Critical)]);
    $finding = mutAppComposer($database, array_reverse(mutAppPackages(16)), elsewhere: 2)->finding;

    expect($finding->severity)->toBe(Severity::Critical)
        ->and($finding->details)->toHaveCount(17)
        ->and($finding->details[0])->toStartWith('[critical] p/016')
        ->and($finding->details[1])->toStartWith('[low] p/001')
        ->and($finding->details[14])->toStartWith('[low] p/014')
        ->and($finding->details[15])->toBe('and 1 more')
        ->and($finding->details[16])->toStartWith('2 packages do not come from Packagist')
        ->and($finding->summary)->toStartWith('16 known');
});

it('does not say "and more" for exactly fifteen', function (): void {
    $matches = [];

    foreach (range(1, 15) as $n) {
        $matches[sprintf('p/%03d@1.0.0', $n)] = ['LOW-1'];
    }

    expect(mutAppComposer(new ScriptedAdvisoryDatabase($matches, ['LOW-1' => mutAppAdvisory('LOW-1')]), mutAppPackages(15))->finding->details)->toHaveCount(15);
});

it('says what it did not check: branches, in a list that is cut, and packages from elsewhere', function (): void {
    $branches = array_map(static fn (int $n): string => 'b/'.$n, range(1, 16));
    $some = mutAppComposer(new ScriptedAdvisoryDatabase, mutAppPackages(1), branches: array_slice($branches, 0, 15), elsewhere: 1)->finding;
    $many = mutAppComposer(new ScriptedAdvisoryDatabase, [], branches: $branches)->finding;

    expect($some->outcome)->toBe(Outcome::Pass)
        ->and($some->details)->toHaveCount(2)
        ->and($some->details[0])->toBe('Installed from a branch, so not checked: '.implode(', ', array_slice($branches, 0, 15)))
        ->and($some->details[1])->toStartWith('1 package does not come from Packagist')
        // Nothing could be asked: that is not a pass
        ->and($many->outcome)->toBe(Outcome::Unknown)
        ->and($many->summary)->toStartWith('No Composer package could be asked about. Installed from a branch')
        ->and($many->summary)->toEndWith('b/15, …');
});

it('passes a project with no package, and does not know a project whose list cannot be read', function (): void {
    $none = mutAppComposer(new ScriptedAdvisoryDatabase, [])->finding;
    $unread = (new ComposerVulnerabilitiesTask(new ScriptedAdvisoryDatabase, mutAppInventory(null), new FakeClock, static fn (): int => 0))->run(null, false)->finding;

    expect($none->outcome)->toBe(Outcome::Pass)->and($none->summary)->toBe('The project has no Composer package installed.')
        ->and($unread->outcome)->toBe(Outcome::Unknown);
});

// Closed and abandoned plugins

/**
 * @param  list<InstalledPlugin>  $plugins
 */
function mutAppInstalled(array $plugins): PluginInventory
{
    return new class($plugins) implements PluginInventory
    {
        public function __construct(private readonly array $installed) {}

        public function plugins(): array
        {
            return $this->installed;
        }
    };
}

/**
 * @param  list<string>  $slugs
 * @return list<InstalledPlugin>
 */
function mutAppNamed(array $slugs): array
{
    return array_map(static fn (string $slug): InstalledPlugin => new InstalledPlugin($slug, '1.0', 'Plugin '.$slug), $slugs);
}

function mutAppListed(string $slug, string $updated = '2026-11-01'): PluginListing
{
    return PluginListing::listed('Plugin '.$slug, '1.0', (new DateTimeImmutable($updated.' 10:00:00 UTC'))->getTimestamp());
}

/**
 * @param  list<InstalledPlugin>  $plugins
 */
function mutAppAbandoned(ScriptedPluginDirectory $directory, array $plugins, int $fetched = 1, ?int $budget = null, ?FakeClock $clock = null): TaskResult
{
    return (new AbandonedPluginsTask($directory, mutAppInstalled($plugins), $clock ?? new FakeClock, static fn (): int => $fetched, $budget))->run(null, false);
}

it('calls a plugin abandoned from two years without a release, to the second', function (): void {
    $directory = new ScriptedPluginDirectory(['old' => mutAppListed('old', '2024-11-25'), 'young' => PluginListing::listed('Plugin young', '1.0', (new DateTimeImmutable('2024-11-25 10:00:01 UTC'))->getTimestamp())]);

    $finding = mutAppAbandoned($directory, mutAppNamed(['old', 'young']))->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::Low)
        ->and($finding->summary)->toBe('1 plugin has had no release on WordPress.org for two years or more: old.')
        ->and($finding->details)->toBe(['old: no release on WordPress.org since 2024-11-25']);
});

it('says why a plugin was closed, in words of its own', function (?string $reason, string $words): void {
    $finding = mutAppAbandoned(new ScriptedPluginDirectory(['a' => PluginListing::closed('Plugin a', '2026-01-02', $reason)]), mutAppNamed(['a']))->finding;

    expect($finding->severity)->toBe(Severity::High)->and($finding->details)->toBe(['a: closed by WordPress.org on 2026-01-02 ('.$words.')']);
})->with([
    ['security-issue', 'security issue'],
    ['author-request', 'at the request of its author'],
    ['guideline-violation', 'it broke the rules of the directory'],
    ['licensing-trademark-violation', 'licence or trademark violation'],
    ['merged-into-core', 'now part of WordPress'],
    ['unused', 'not used'],
    [null, 'reason not published'],
]);

it('says a closing without a date, and sums up closed and old plugins together, closed first', function (): void {
    $slugs = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'o1', 'o2'];
    $answers = [];

    foreach ($slugs as $slug) {
        $answers[$slug] = str_starts_with($slug, 'c') ? PluginListing::closed('Plugin '.$slug, null, 'unused') : mutAppListed($slug, '2020-01-01');
    }

    $finding = mutAppAbandoned(new ScriptedPluginDirectory($answers), mutAppNamed(array_reverse($slugs)))->finding;

    expect($finding->severity)->toBe(Severity::High)
        ->and($finding->summary)->toBe('6 plugins have been closed by WordPress.org: c6, c5, c4, c3, c2. 2 plugins have had no release on WordPress.org for two years or more: o2, o1.')
        ->and($finding->details)->toHaveCount(8)
        ->and($finding->details[0])->toBe('c6: closed by WordPress.org (not used)')
        ->and($finding->details[6])->toStartWith('o2:');
});

it('lists fifteen closed plugins and counts the rest', function (int $count): void {
    $slugs = array_map(static fn (int $n): string => 'c'.$n, range(1, $count));
    $answers = array_combine($slugs, array_map(static fn (string $slug): PluginListing => PluginListing::closed('Plugin '.$slug, null, null), $slugs));

    $finding = mutAppAbandoned(new ScriptedPluginDirectory($answers), mutAppNamed($slugs))->finding;

    expect($finding->details)->toHaveCount($count)->and(in_array('and 1 more', $finding->details, true))->toBe($count === 16);
})->with([15, 16]);

it('does not count against a plugin a listing of another one under the same folder, but mentions it', function (): void {
    $directory = new ScriptedPluginDirectory([
        'closed' => PluginListing::closed('Something entirely different', null, null),
        'old' => PluginListing::listed('Something entirely different', '9', 1000),
        'fine' => PluginListing::listed('Something entirely different', '9', (new DateTimeImmutable('2026-11-01 UTC'))->getTimestamp()),
    ]);

    $finding = mutAppAbandoned($directory, mutAppNamed(['closed', 'old', 'fine']))->finding;

    expect($finding->outcome)->toBe(Outcome::Pass)
        ->and($finding->summary)->toBe('No plugin of this site was recognised on WordPress.org: nothing to say about closed or old plugins.')
        ->and($finding->details)->toHaveCount(1)
        ->and($finding->details[0])->toEndWith('not counted: closed, old');
});

it('does not ask about a plugin that is updated elsewhere, and says how many', function (): void {
    $directory = new ScriptedPluginDirectory(['a' => mutAppListed('a')]);
    $plugins = [...mutAppNamed(['a']), new InstalledPlugin('mine', '1', 'Mine', 'https://example.com/updates')];

    $finding = mutAppAbandoned($directory, $plugins)->finding;

    expect($directory->asked)->toBe(['a'])
        ->and($finding->summary)->toBe('The 1 plugin recognised on WordPress.org is open and has had a release in the last two years.')
        ->and($finding->details)->toBe(['1 plugin says it is updated from somewhere else and was not asked about.']);
});

it('does not know about a plugin whose listing cannot be read or has no date, and never counts it', function (): void {
    $directory = new ScriptedPluginDirectory(['down' => new TransportException('x'), 'undated' => PluginListing::listed('Plugin undated', '1.0', null), 'fine' => mutAppListed('fine'), 'gone' => PluginListing::absent()]);

    $finding = mutAppAbandoned($directory, mutAppNamed(['down', 'undated', 'gone', 'fine']))->finding;

    expect($finding->outcome)->toBe(Outcome::Unknown)
        ->and($finding->summary)->toBe('WordPress.org could not be asked about: down, undated')
        ->and($directory->asked)->toBe(['down', 'undated', 'gone', 'fine']);
});

it('cuts the list of the plugins it could not ask about', function (int $count): void {
    $slugs = array_map(static fn (int $n): string => 'p'.$n, range(1, $count));

    $finding = mutAppAbandoned(new ScriptedPluginDirectory(down: true), mutAppNamed($slugs))->finding;

    expect($finding->summary)->toBe('WordPress.org could not be asked about: '.implode(', ', array_slice($slugs, 0, 15)).($count > 15 ? ', …' : ''));
})->with([15, 16]);

it('stops past its budget, not at it, and asks for a run that goes on only when the directory answered', function (): void {
    $run = static function (int $fetched): array {
        $clock = new FakeClock;
        $directory = new ScriptedPluginDirectory(onAsk: static fn () => $clock->advance('PT10S'));

        return [mutAppAbandoned($directory, mutAppNamed(['a', 'b', 'c', 'd']), $fetched, 10, $clock), $directory];
    };

    [$moved, $directory] = $run(1);
    [$stuck] = $run(0);

    expect($directory->asked)->toBe(['a', 'b'])
        ->and($moved->finding->outcome)->toBe(Outcome::Unknown)
        ->and($moved->finding->summary)->toBe('2 plugins were not reached in the time allowed.')
        ->and($moved->unfinished)->toBeTrue()
        ->and($stuck->unfinished)->toBeFalse();
});

it('keeps asking for a run that goes on when a failure was already found', function (): void {
    $clock = new FakeClock;
    $directory = new ScriptedPluginDirectory(['a' => PluginListing::closed('Plugin a', null, null)], onAsk: static fn () => $clock->advance('PT11S'));

    $result = mutAppAbandoned($directory, mutAppNamed(['a', 'b']), 1, 10, $clock);

    expect($result->finding->outcome)->toBe(Outcome::Fail)
        ->and($result->unfinished)->toBeTrue()
        ->and($result->finding->details)->toContain('1 plugin was not reached in the time allowed.');
});

it('asks for no run that goes on when everything was read', function (): void {
    expect(mutAppComposer(new ScriptedAdvisoryDatabase, mutAppPackages(1))->unfinished)->toBeFalse()
        ->and(mutAppAbandoned(new ScriptedPluginDirectory, mutAppNamed(['a']))->unfinished)->toBeFalse();
});

it('goes on with the other flaws of a package after two records of one', function (): void {
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['A-1', 'B-1', 'C-1']], [
        'A-1' => mutAppAdvisory('A-1', summary: 'one', fixed: ['1.10.0']),
        'B-1' => mutAppAdvisory('B-1', Severity::Low, ['A-1'], 'one again', ['1.9.0']),
        'C-1' => mutAppAdvisory('C-1', summary: 'two'),
    ]);

    expect(mutAppComposer($database, mutAppPackages(1))->finding->details)->toBe([
        // The highest fix of the two records, whatever order they came in
        '[high] p/001 1.0.0: one (A-1), fixed in 1.10.0',
        '[high] p/001 1.0.0: two (C-1)',
    ]);
});

it('gives the identifier of the record when it and an alias are two CVE numbers', function (): void {
    $database = new ScriptedAdvisoryDatabase(['p/001@1.0.0' => ['CVE-2026-0001']], ['CVE-2026-0001' => mutAppAdvisory('CVE-2026-0001', aliases: ['CVE-2026-0002'])]);

    expect(mutAppComposer($database, mutAppPackages(1))->finding->details[0])->toEndWith('(CVE-2026-0001)');
});

it('counts what was not reached among the plugins it asks about only, and keeps the notes of a failure', function (): void {
    $clock = new FakeClock;
    $directory = new ScriptedPluginDirectory(['a' => PluginListing::closed('Plugin a', null, null)], onAsk: static fn () => $clock->advance('PT11S'));
    $plugins = [new InstalledPlugin('mine', '1', 'Mine', 'https://example.com/u'), ...mutAppNamed(['a', 'b', 'c'])];

    $finding = mutAppAbandoned($directory, $plugins, 1, 10, $clock)->finding;

    expect($finding->details)->toBe([
        'a: closed by WordPress.org (reason not published)',
        '2 plugins were not reached in the time allowed.',
        '1 plugin says it is updated from somewhere else and was not asked about.',
    ]);
});
