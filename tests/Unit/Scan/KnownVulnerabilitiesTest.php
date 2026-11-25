<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\KnownVulnerabilitiesTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\CachedVulnerabilityFeed;
use Pollora\Hornet\Domain\Scan\Software;
use Pollora\Hornet\Domain\Scan\Vulnerability;
use Pollora\Hornet\Domain\Scan\VulnerabilityFeed;
use Pollora\Hornet\Tests\Fixtures\ArrayPluginInventory;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryVulnerabilityCache;
use Pollora\Hornet\Tests\Fixtures\ScriptedVulnerabilityFeed;

function vuln(string $id = 'CVE-2026-0001', Severity $severity = Severity::High, ?string $fix = '2.0', bool $unfixed = false): Vulnerability
{
    return new Vulnerability($id, 'A flaw in '.$id, $severity, $fix, $unfixed, null);
}

function vulnerabilityTask(VulnerabilityFeed $feed, ?FakeClock $clock = null, ?int $budget = null, ?Closure $stale = null): KnownVulnerabilitiesTask
{
    return new KnownVulnerabilitiesTask($feed, new ArrayPluginInventory(['akismet' => '5.3', 'two-factor' => '0.17']), new ArrayPluginInventory(['twentytwentyone' => '2.0']), '7.1.5', $clock ?? new FakeClock, $stale ?? static fn (): array => [], $budget);
}

it('asks about the core, each plugin and each theme, by name and version', function (): void {
    $feed = new ScriptedVulnerabilityFeed;

    $result = vulnerabilityTask($feed)->run(null, false);

    expect($feed->asked)->toBe(['core::7.1.5', 'plugin:akismet:5.3', 'plugin:two-factor:0.17', 'theme:twentytwentyone:2.0'])
        ->and($result->finding->outcome)->toBe(Outcome::Pass)
        ->and($result->finding->summary)->toBe('No known vulnerability in the 4 versions that were asked about.');
});

it('lists what it found, the worst first, with where to update to', function (): void {
    $feed = new ScriptedVulnerabilityFeed([
        'plugin:akismet:5.3' => [vuln('CVE-2026-0002', Severity::Medium, '5.4')],
        'plugin:two-factor:0.17' => [vuln('CVE-2026-0003', Severity::Critical, null, true), vuln('CVE-2026-0004', Severity::High, '0.18')],
    ]);

    $finding = vulnerabilityTask($feed)->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::Critical)
        ->and($finding->summary)->toBe('3 known vulnerabilities in what the site runs.')
        ->and($finding->details)->toBe([
            '[critical] two-factor 0.17 (plugin): A flaw in CVE-2026-0003 (CVE-2026-0003), not fixed',
            '[high] two-factor 0.17 (plugin): A flaw in CVE-2026-0004 (CVE-2026-0004), fixed in 0.18',
            '[medium] akismet 5.3 (plugin): A flaw in CVE-2026-0002 (CVE-2026-0002), fixed in 5.4',
        ])
        ->and($finding->fix)->toContain('Hornet changes nothing');
});

it('says one vulnerability in the singular, and counts what it does not list', function (): void {
    $one = vulnerabilityTask(new ScriptedVulnerabilityFeed(['core::7.1.5' => [vuln()]]))->run(null, false)->finding;
    $many = vulnerabilityTask(new ScriptedVulnerabilityFeed(['core::7.1.5' => array_map(static fn (int $n): Vulnerability => vuln('CVE-2026-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)), range(1, 20))]))->run(null, false)->finding;

    expect($one->summary)->toBe('1 known vulnerability in what the site runs.')
        ->and($many->details)->toHaveCount(16)->and($many->details[15])->toBe('and 5 more');
});

it('names what it could not ask, and does not call that a pass', function (): void {
    $feed = new ScriptedVulnerabilityFeed(['plugin:akismet:5.3' => new TransportException('down')]);

    $finding = vulnerabilityTask($feed)->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Pass)->and($finding->details)->toBe(['Not checked: akismet 5.3 (plugin)']);
});

it('says it does not know when the feed does not answer at all', function (): void {
    $result = vulnerabilityTask(new ScriptedVulnerabilityFeed(down: true))->run(null, false);

    expect($result->finding->outcome)->toBe(Outcome::Unknown)->and($result->finding->summary)->toContain('did not answer');
});

it('stops asking when the time is up', function (): void {
    $clock = new FakeClock;
    $feed = new ScriptedVulnerabilityFeed(onAsk: static fn () => $clock->advance('PT10S'));

    $finding = vulnerabilityTask($feed, $clock, 15)->run(null, false)->finding;

    expect($feed->asked)->toHaveCount(2)->and($finding->details)->toBe(['Not checked: 2 more (out of time)']);
});

it('says when the answer is a copy of a few days ago', function (): void {
    $finding = vulnerabilityTask(new ScriptedVulnerabilityFeed, stale: static fn (): array => ['akismet'])->run(null, false)->finding;

    expect($finding->details)->toBe(['From a copy of a few days ago, the feed being down: akismet']);
});

describe('the cache', function (): void {
    it('does not ask again within the time a copy is fresh', function (): void {
        $inner = new ScriptedVulnerabilityFeed(['plugin:akismet:5.3' => [vuln()]]);
        $feed = new CachedVulnerabilityFeed($inner, new InMemoryVulnerabilityCache, new FakeClock);

        $first = $feed->of(Software::Plugin, 'akismet', '5.3');
        $second = $feed->of(Software::Plugin, 'akismet', '5.3');

        expect($inner->asked)->toHaveCount(1)->and($second)->toEqual($first)->and($feed->staleAnswers())->toBe([]);
    });

    it('asks again when the copy is old, and keeps the new answer', function (): void {
        $clock = new FakeClock;
        $inner = new ScriptedVulnerabilityFeed;
        $feed = new CachedVulnerabilityFeed($inner, new InMemoryVulnerabilityCache, $clock);

        $feed->of(Software::Plugin, 'akismet', '5.3');
        $clock->advance('PT7H');
        $feed->of(Software::Plugin, 'akismet', '5.3');

        expect($inner->asked)->toHaveCount(2);
    });

    it('does not mix two versions of the same plugin', function (): void {
        $inner = new ScriptedVulnerabilityFeed(['plugin:akismet:5.3' => [vuln()]]);
        $feed = new CachedVulnerabilityFeed($inner, new InMemoryVulnerabilityCache, new FakeClock);

        expect($feed->of(Software::Plugin, 'akismet', '5.3'))->toHaveCount(1)
            ->and($feed->of(Software::Plugin, 'akismet', '5.4'))->toBe([]);
    });

    it('uses an old copy when the feed is down, and says so', function (): void {
        $clock = new FakeClock;
        $inner = new ScriptedVulnerabilityFeed(['plugin:akismet:5.3' => [vuln()]]);
        $feed = new CachedVulnerabilityFeed($inner, new InMemoryVulnerabilityCache, $clock);
        $feed->of(Software::Plugin, 'akismet', '5.3');
        $inner->down = true;
        $clock->advance('P2D');

        expect($feed->of(Software::Plugin, 'akismet', '5.3'))->toHaveCount(1)->and($feed->staleAnswers())->toBe(['akismet']);
    });

    it('does not use a copy that is too old, nor a copy it never had', function (): void {
        $clock = new FakeClock;
        $inner = new ScriptedVulnerabilityFeed;
        $feed = new CachedVulnerabilityFeed($inner, new InMemoryVulnerabilityCache, $clock);
        $feed->of(Software::Plugin, 'akismet', '5.3');
        $inner->down = true;
        $clock->advance('P4D');

        expect(fn () => $feed->of(Software::Plugin, 'akismet', '5.3'))->toThrow(TransportException::class)
            ->and(fn () => $feed->of(Software::Plugin, 'never-seen', '1.0'))->toThrow(TransportException::class);
    });
});
