<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\Advisory;
use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\CachedAdvisoryDatabase;
use Pollora\Hornet\Domain\Scan\CachedPluginDirectory;
use Pollora\Hornet\Domain\Scan\CachedVulnerabilityFeed;
use Pollora\Hornet\Domain\Scan\ComposerPackage;
use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Domain\Scan\Software;
use Pollora\Hornet\Domain\Scan\Vulnerability;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryAdvisoryCache;
use Pollora\Hornet\Tests\Fixtures\InMemoryPluginListingCache;
use Pollora\Hornet\Tests\Fixtures\InMemoryVulnerabilityCache;
use Pollora\Hornet\Tests\Fixtures\ScriptedAdvisoryDatabase;
use Pollora\Hornet\Tests\Fixtures\ScriptedPluginDirectory;
use Pollora\Hornet\Tests\Fixtures\ScriptedVulnerabilityFeed;

/*
 * What is kept of the answers of the outside, for how long, and what a damaged copy is worth.
 */

it('keeps the answers of each kind, name and release apart', function (): void {
    $cache = new InMemoryVulnerabilityCache;
    (new CachedVulnerabilityFeed(new ScriptedVulnerabilityFeed, $cache, new FakeClock))->of(Software::Plugin, 'akismet', '5.0');

    expect(array_keys($cache->stored))->toBe(['plugin:akismet:5.0']);
});

it('asks the feed again when the copy is exactly six hours old', function (): void {
    $clock = new FakeClock;
    $inner = new ScriptedVulnerabilityFeed;
    $feed = new CachedVulnerabilityFeed($inner, new InMemoryVulnerabilityCache, $clock);
    $feed->of(Software::Plugin, 'a', '1');
    $clock->advance('PT21599S');
    $feed->of(Software::Plugin, 'a', '1');
    expect($inner->asked)->toHaveCount(1);

    $clock->advance('PT1S');
    $feed->of(Software::Plugin, 'a', '1');
    expect($inner->asked)->toHaveCount(2);
});

it('stops using an old copy when it is three days old', function (): void {
    $clock = new FakeClock;
    $inner = new ScriptedVulnerabilityFeed;
    $feed = new CachedVulnerabilityFeed($inner, new InMemoryVulnerabilityCache, $clock);
    $feed->of(Software::Plugin, 'a', '1');
    $inner->down = true;
    $clock->advance('PT259199S');
    expect($feed->of(Software::Plugin, 'a', '1'))->toBe([]);

    $clock->advance('PT1S');
    expect(fn (): array => $feed->of(Software::Plugin, 'a', '1'))->toThrow(TransportException::class);
});

it('names once, as a list, what was answered from an old copy', function (): void {
    $clock = new FakeClock;
    $inner = new ScriptedVulnerabilityFeed;
    $feed = new CachedVulnerabilityFeed($inner, new InMemoryVulnerabilityCache, $clock);
    $ask = static function () use ($feed): void {
        $feed->of(Software::Plugin, 'a', '1');
        $feed->of(Software::Plugin, 'a', '1');
        $feed->of(Software::Core, '', '7.0');
    };
    $ask();
    $inner->down = true;
    $clock->advance('PT7H');
    $ask();
    $ask();

    expect($feed->staleAnswers())->toBe(['a', 'core']);
});

it('asks the directory again when its answer is exactly a day old', function (): void {
    $clock = new FakeClock;
    $inner = new ScriptedPluginDirectory;
    $directory = new CachedPluginDirectory($inner, new InMemoryPluginListingCache, $clock);
    $directory->of('a');
    $clock->advance('PT86400S');
    $directory->of('a');

    expect($inner->asked)->toHaveCount(2);
});

it('asks the database only for what it does not have, and answers in the order asked', function (): void {
    $inner = new ScriptedAdvisoryDatabase(['a/a@1' => ['A'], 'b/b@1' => ['B']]);
    $database = new CachedAdvisoryDatabase($inner, new InMemoryAdvisoryCache);
    $a = new ComposerPackage('a/a', '1');
    $b = new ComposerPackage('b/b', '1');
    $database->affecting([$a]);

    $answers = $database->affecting([$b, $a]);

    expect($inner->batches)->toBe([['a/a@1'], ['b/b@1']])
        ->and(array_map(static fn (array $found): array => array_keys($found), $answers))->toBe([['B'], ['A']]);

    $c = new ComposerPackage('c/c', '1');
    expect(array_keys($database->affecting([$a, $c])))->toBe([0, 1])
        ->and($inner->batches[2])->toBe(['c/c@1']);
});

it('counts a record it had to fetch as progress', function (): void {
    $inner = new ScriptedAdvisoryDatabase(records: ['X' => new Advisory('X', [], 's', Severity::High)]);
    $database = new CachedAdvisoryDatabase($inner, new InMemoryAdvisoryCache);
    $database->advisory('X', 'now');

    expect($database->fetched())->toBe(1);
});

describe('the release that fixes a flaw', function (): void {
    $advisory = static fn (array ...$ranges): Advisory => new Advisory('X', [], 's', Severity::High, array_values($ranges));
    $range = static fn (array $events, string $package = 'a/a'): array => ['package' => $package, 'events' => $events];

    it('looks past the ranges of other packages', function () use ($advisory, $range): void {
        $record = $advisory($range([['introduced', '0'], ['fixed', '9.0']], 'z/z'), $range([['introduced', '0'], ['fixed', '2.0']]));

        expect($record->fixedFor('a/a', '1.0'))->toBe('2.0');
    });

    it('is told only for a stretch that holds the version', function (array $events, string $version, ?string $fixed) use ($advisory, $range): void {
        expect($advisory($range($events))->fixedFor('a/a', $version))->toBe($fixed);
    })->with([
        'a fix with no start' => [[['fixed', '2.0']], '1.0', null],
        'a second stretch' => [[['introduced', '1.0'], ['fixed', '2.0'], ['introduced', '3.0'], ['fixed', '4.0']], '3.5', '4.0'],
        'past the last affected release' => [[['introduced', '0'], ['last_affected', '1.5'], ['fixed', '3.0']], '2.0', null],
        'at the last affected release' => [[['introduced', '0'], ['last_affected', '2.0'], ['fixed', '3.0']], '2.0', '3.0'],
        'the release that is the fix' => [[['introduced', '0'], ['fixed', '2.0'], ['fixed', '5.0']], '2.0', null],
        'after the fix' => [[['introduced', '0'], ['fixed', '2.0'], ['fixed', '5.0']], '3.0', null],
    ]);
});

it('reads a stored record back as it was', function (): void {
    $record = new Advisory('X', ['CVE-1'], 's', Severity::High, [['package' => 'a/a', 'events' => [['introduced', '0']]]], true);

    expect(Advisory::fromArray($record->toArray()))->toEqual($record)
        ->and(Advisory::fromArray(['id' => 'X', 'summary' => 's', 'severity' => 'high'])?->withdrawn)->toBeFalse()
        ->and(Advisory::fromArray(['id' => 'X', 'summary' => 's', 'severity' => 'high', 'aliases' => [3, 'CVE-1']])?->aliases)->toBe(['CVE-1']);
});

it('refuses a damaged record rather than reading part of it', function (mixed $stored): void {
    expect(Advisory::fromArray($stored))->toBeNull();
})->with(function (): array {
    $good = ['id' => 'X', 'summary' => 's', 'severity' => 'high'];
    $range = static fn (mixed $range): array => [...$good, 'ranges' => [$range]];
    $event = static fn (mixed $event): array => $range(['package' => 'a/a', 'events' => [$event]]);

    return [
        'text' => ['x'],
        'no id' => [['summary' => 's', 'severity' => 'high']],
        'no summary' => [['id' => 'X', 'severity' => 'high']],
        'no severity' => [['id' => 'X', 'summary' => 's']],
        'a number for a severity' => [[...$good, 'severity' => 5]],
        'a range that is text' => [$range('x')],
        'a package that is a number' => [$range(['package' => 5, 'events' => []])],
        'events that are text' => [$range(['package' => 'a/a', 'events' => 'x'])],
        'an event that is text' => [$event('xy')],
        'a kind that is a number' => [$event([5, '1.0'])],
        'a version that is a number' => [$event(['fixed', 5])],
    ];
});

it('reads a stored vulnerability back, unfixed only when it says so', function (): void {
    $flaw = new Vulnerability('CVE-1', 't', Severity::High, null, true, 'https://example.com');

    expect(Vulnerability::fromArray($flaw->toArray()))->toEqual($flaw)
        ->and(Vulnerability::fromArray(['id' => 'a', 'title' => 't', 'severity' => 'low'])?->unfixed)->toBeFalse();
});

it('reads a stored listing back as it was', function (PluginListing $listing): void {
    expect(PluginListing::fromArray($listing->toArray()))->toEqual($listing);
})->with([
    'listed' => [fn (): PluginListing => PluginListing::listed('Name', '1.2', 123)],
    'closed' => [fn (): PluginListing => PluginListing::closed('Name', '2026-01-01', 'security-issue')],
]);

it('reads a damaged listing with care', function (): void {
    $listing = PluginListing::fromArray(['status' => 'listed', 'name' => 'n', 'version' => 5, 'updated_at' => 'x']);

    expect($listing?->version)->toBeNull()
        ->and($listing?->updatedAt)->toBeNull()
        ->and(PluginListing::fromArray(['status' => 5, 'name' => 'n']))->toBeNull()
        ->and(PluginListing::fromArray(['status' => 'listed']))->toBeNull()
        ->and(PluginListing::fromArray('x'))->toBeNull();
});

it('recognises the plugin that a listing is about', function (string $installed, string $listed, bool $same): void {
    expect(PluginListing::listed($listed, null, null)->isSamePlugin(new InstalledPlugin('p', '1.0', $installed)))->toBe($same);
})->with([
    'no name installed, a listing that is only a slogan' => ['', ': tools', false],
    'two words that start a longer name' => ['Contact Form', 'Contact Form Seven', true],
    'the longer name installed' => ['WP Mail SMTP by WPForms', 'WP Mail SMTP', true],
    'a slogan after a dash written as an entity' => ['Akismet', 'Akismet &#8211; Spam Protection', true],
    'two slogans' => ['Akismet - Anti-spam', 'Akismet - Protection', true],
    'an entity in the name' => ['Fish &amp; Chips', 'Fish & Chips', true],
    'an HTML 5 apostrophe' => ['Bob&apos;s Tools', "Bob's Tools", true],
    'one word that starts a longer name' => ['Akismet', 'Akismet Extended', false],
    'an entity at the start of a longer name' => ['Fish &amp; Chips', 'Fish & Chips Deluxe', true],
    'an HTML 5 apostrophe in a longer name' => ['Bob&apos;s Tools Pro', "Bob's Tools", true],
]);

it('refuses damaged pins and baselines, and keeps their text hashes', function (): void {
    expect(Pins::fromArray('x'))->toBeNull()
        ->and(Pins::fromArray(['hashes' => []]))->toBeNull()
        ->and(Pins::fromArray(['scope' => 's']))->toBeNull()
        ->and(Pins::fromArray(['scope' => 's', 'hashes' => ['a' => 'h', 'b' => 1]])?->hashes)->toBe(['a' => 'h'])
        ->and(Baseline::fromArray(['version' => '1', 'hashes' => ['a' => 'h', 'b' => 1]])?->hashes)->toBe(['a' => 'h']);
});

it('stores a snapshot as plain data and leaves out what does not fit', function (): void {
    $snapshot = new ScanSnapshot(new DateTimeImmutable('@100'), ['core' => Finding::pass('core', 'ok')], ['core' => new Pins('s', ['a' => 'h'])], ['x']);

    expect($snapshot->toArray()['pins'])->toBe(['core' => ['scope' => 's', 'hashes' => ['a' => 'h']]]);

    $read = ScanSnapshot::fromArray(['at' => 100, 'findings' => [], 'pins' => ['bad' => 'x', 'core' => ['scope' => 's', 'hashes' => []]], 'unfinished' => [1, 'x']]);

    expect(array_keys($read->pins ?? []))->toBe(['core'])
        ->and($read?->unfinished)->toBe(['x']);
});

it('stops waiting for a scan after fifteen minutes', function (): void {
    $state = new ScanState(ScanState::RUNNING, 'core', 1000);

    expect($state->isActive(1899))->toBeTrue()
        ->and($state->isActive(1900))->toBeFalse();
});

it('reads a stored idle state back with its time', function (): void {
    expect(ScanState::fromArray(['status' => 'idle', 'task' => null, 'since' => 5])->since)->toBe(5);
});
