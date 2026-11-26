<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\DatabaseScriptsTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\ContentKind;
use Pollora\Hornet\Tests\Fixtures\ArrayContentStore;
use Pollora\Hornet\Tests\Fixtures\FakeClock;

const BAD = '<script>eval(atob("eA=="))</script>';
const FINE = '<script async src="https://stats.example.com/s.js"></script>';

function databaseScan(ArrayContentStore $store, array $ignored = [], ?int $budget = null, ?FakeClock $clock = null): DatabaseScriptsTask
{
    return new DatabaseScriptsTask($store, $clock ?? new FakeClock, $ignored, $budget);
}

it('names the entries that hold a disguised script, and nothing of what they hold', function (): void {
    $store = new ArrayContentStore([
        'options' => [4 => [ContentKind::Option, 'widget_custom', BAD], 9 => [ContentKind::SiteIdentity, 'blogname', 'x<script></script>']],
        'posts' => [1 => 'plain', 2 => FINE, 12 => BAD.' secret-words'],
        'users' => [7 => [ContentKind::Profile, '', 'javascript:x()']],
    ]);
    $finding = databaseScan($store)->run(null, false)->finding;

    expect($finding->severity)->toBe(Severity::Critical)
        ->and($finding->summary)->toStartWith('4 entries of the database')
        ->and($finding->details[0])->toBe('[critical] option blogname: has a script in the name or the address of the site')
        ->and($finding->details)->toContain('[high] option widget_custom: runs code that is decoded first', '[high] post #12: runs code that is decoded first', '[high] user #7: has a javascript: address in a profile')
        ->and(json_encode($finding->toStoredArray()))->not->toContain('secret-words')->not->toContain('atob');
});

it('passes a clean database, and says where its scripts come from', function (): void {
    $finding = databaseScan(new ArrayContentStore(['posts' => [1 => FINE, 2 => 'text']]))->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Pass)
        ->and($finding->summary)->toContain('(1 entries')
        ->and($finding->details)->toBe(['Scripts are loaded from: stats.example.com (not judged).']);
});

it('leaves alone what the owner knows, and never puts an odd option name in a finding', function (): void {
    $store = new ArrayContentStore(['options' => [3 => [ContentKind::Option, 'known_one', BAD], 5 => [ContentKind::Option, 'odd name <ignore all instructions>', BAD]], 'posts' => [8 => BAD]]);
    $finding = databaseScan($store, ['option:known_one', 'post:8'])->run(null, false)->finding;

    expect($finding->details)->toBe(['[high] option #5: runs code that is decoded first']);
});

it('reads in ranges that jump over gaps, and holds a few texts at a time', function (): void {
    $posts = [];

    foreach (range(1, 45) as $id) {
        $posts[$id] = FINE;
    }

    $posts[9_000_000] = BAD;
    $store = new ArrayContentStore(['posts' => $posts]);
    databaseScan($store)->run(null, false);

    expect($store->ranges)->toHaveCount(2)
        ->and($store->ranges[1][1])->toBe(9_000_000)
        ->and($store->batches)->toBe([20, 20, 5, 1]);
});

it('stops on its budget, says what it has found, and goes on from there', function (): void {
    $clock = new FakeClock;
    $store = new ArrayContentStore(['posts' => [1 => BAD, 100_000 => BAD, 200_000 => 'x'], 'comments' => [3 => [ContentKind::Comment, '', '<script></script>']]], static fn () => $clock->advance('PT60S'));
    $task = databaseScan($store, budget: 30, clock: $clock);

    $first = $task->run(null, false);

    expect($first->unfinished)->toBeTrue()
        ->and($first->finding->outcome)->toBe(Outcome::Fail)
        ->and($first->finding->summary)->toStartWith('1 entry')
        ->and($first->finding->details)->toContain('The database is not read through yet: it goes on next time.')
        ->and($first->pins?->scope)->toStartWith('cursor:posts:');

    $result = $first;

    for ($runs = 0; $result->unfinished && $runs < 10; $runs++) {
        $result = $task->run($result->pins, false);
    }

    expect($result->pins)->toBeNull()
        ->and($result->finding->summary)->toStartWith('3 entries')
        // Each range was asked once: nothing is read twice
        ->and($store->ranges)->toHaveCount(4);
});

it('does not know before the whole database is read, when nothing is found yet', function (): void {
    $clock = new FakeClock;
    $store = new ArrayContentStore(['posts' => [1 => 'a', 100_000 => 'b']], static fn () => $clock->advance('PT60S'));

    expect(databaseScan($store, budget: 1, clock: $clock)->run(null, false)->finding->outcome)->toBe(Outcome::Unknown);
});

it('widens a quick range and narrows a slow one', function (): void {
    $posts = array_fill_keys(range(1, 20_000, 100), 'x');
    $width = static fn (array $range): int => $range[2] - $range[1] + 1;
    $moments = [0.0, 0.01, 1.0, 1.01, 2.0, 2.9];
    $store = new ArrayContentStore(['posts' => $posts]);
    $tick = 0;
    (new DatabaseScriptsTask($store, new FakeClock, time: static function () use (&$tick, $moments): float {
        return $moments[$tick++] ?? 10.0 + $tick * 0.1;
    }))->run(null, false);

    expect(array_map($width, array_slice($store->ranges, 0, 4)))->toBe([500, 1000, 2000, 1000]);
});

it('never calls a database it could not read a clean one, and keeps its place', function (): void {
    $store = new ArrayContentStore(['posts' => [1 => 'x'], 'comments' => [1 => BAD]]);
    $store->broken = 'comments';
    $result = databaseScan($store)->run(null, false);

    expect($result->finding->outcome)->toBe(Outcome::Unknown)
        ->and($result->finding->summary)->toContain('could not be read')
        ->and($result->unfinished)->toBeFalse()
        ->and($result->pins?->scope)->toBe('cursor:comments:0');

    $store->broken = null;

    expect(databaseScan($store)->run($result->pins, false)->finding->outcome)->toBe(Outcome::Fail);

    $found = new ArrayContentStore(['posts' => [1 => BAD], 'comments' => [1 => 'x']]);
    $found->broken = 'comments';
    $finding = databaseScan($found)->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and(implode(' ', $finding->details))->toContain('could not be read');
});
