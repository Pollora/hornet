<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Journal\Decision;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Tests\Fixtures\InMemoryWindowCounter;

it('writes the first failures of a network, says once that it went past, and writes no more', function (): void {
    $guard = new FloodGuard(new InMemoryWindowCounter, perNetwork: 3);

    $decisions = array_map(static fn (): Decision => $guard->decide('203.0.113.0'), range(1, 8));

    expect($decisions)->toBe([Decision::Write, Decision::Write, Decision::Write, Decision::FloodNetwork, Decision::Drop, Decision::Drop, Decision::Drop, Decision::Drop]);
});

it('counts each network on its own', function (): void {
    $guard = new FloodGuard(new InMemoryWindowCounter, perNetwork: 2);

    foreach (range(1, 5) as $ignored) {
        $guard->decide('203.0.113.0');
    }

    expect($guard->decide('198.51.100.0'))->toBe(Decision::Write)->and($guard->decide('203.0.113.0'))->toBe(Decision::Drop);
});

it('holds the site to a number of events an hour, however many networks they come from, and says it once', function (): void {
    $guard = new FloodGuard(new InMemoryWindowCounter, perNetwork: 5, perHour: 10);
    $decisions = [];

    // A botnet: every request from another network, so that the limit of a network is never reached
    foreach (range(1, 40) as $n) {
        $decisions[] = $guard->decide('10.0.'.$n.'.0');
    }

    $counts = array_count_values(array_map(static fn (Decision $d): string => $d->name, $decisions));

    expect($counts)->toBe(['Write' => 10, 'FloodSite' => 1, 'Drop' => 29])
        ->and($decisions[10])->toBe(Decision::FloodSite);
});

it('treats a visitor with no known address as one network', function (): void {
    $counter = new InMemoryWindowCounter;
    $guard = new FloodGuard($counter, perNetwork: 1);

    expect($guard->decide(''))->toBe(Decision::Write)->and($guard->decide(''))->toBe(Decision::FloodNetwork)->and(array_keys($counter->counts))->toContain('journal:network:unknown');
});

it('starts again when the window does', function (): void {
    $counter = new InMemoryWindowCounter;
    $guard = new FloodGuard($counter, perNetwork: 1);
    $guard->decide('203.0.113.0');
    $guard->decide('203.0.113.0');

    $counter->reset();

    expect($guard->decide('203.0.113.0'))->toBe(Decision::Write);
});

it('says its limits, so that the documentation and the events can quote them', function (): void {
    $guard = new FloodGuard(new InMemoryWindowCounter);

    expect([$guard->perNetwork(), $guard->window(), $guard->perHour()])->toBe([5, 600, 120]);
});

it('counts a scope apart, so that one kind of failure cannot hide another', function (): void {
    $counter = new InMemoryWindowCounter;
    $logins = new FloodGuard($counter, perNetwork: 1);
    $secondFactors = new FloodGuard($counter, perNetwork: 1, scope: 'journal:second-factor');
    $logins->decide('203.0.113.0');
    $logins->decide('203.0.113.0');

    expect($secondFactors->decide('203.0.113.0'))->toBe(Decision::Write);
});
