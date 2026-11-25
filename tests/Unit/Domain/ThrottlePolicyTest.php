<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Domain\Login\ThrottlePolicy;

it('counts failures and locks at the limit', function (): void {
    $policy = new ThrottlePolicy(3, 900, 600);
    $attempts = new Attempts;

    foreach ([1000, 1010] as $now) {
        $attempts = $policy->failed($attempts, $now);
        expect($policy->isLocked($attempts, $now))->toBeFalse();
    }

    $attempts = $policy->failed($attempts, 1020);

    expect($policy->isLocked($attempts, 1020))->toBeTrue()->and($attempts->lockedUntil)->toBe(1620)->and($attempts->lockouts)->toBe(1)
        ->and($policy->secondsLeft($attempts, 1020))->toBe(600)->and($policy->isLocked($attempts, 1620))->toBeFalse();
});

it('forgets failures that are older than the window', function (): void {
    $policy = new ThrottlePolicy(3, 900, 600);
    $attempts = $policy->failed($policy->failed(new Attempts, 1000), 1100);
    $later = $policy->failed($attempts, 1000 + 901);

    expect($later->failures)->toBe(1)->and($policy->isLocked($later, 2000))->toBeFalse();
});

it('makes each lockout of the same address twice as long, up to a day', function (): void {
    $policy = new ThrottlePolicy(1, 900, 900);
    $attempts = new Attempts;
    $durations = [];
    $now = 10000;

    for ($i = 0; $i < 9; $i++) {
        $attempts = $policy->failed($attempts, $now);
        $durations[] = $attempts->lockedUntil - $now;
        $now = $attempts->lockedUntil + 1;
    }

    expect($durations)->toBe([900, 1800, 3600, 7200, 14400, 28800, 57600, 86400, 86400]);
});

it('does not stretch a lock that is on', function (): void {
    $policy = new ThrottlePolicy(2, 900, 600);
    $locked = $policy->failed($policy->failed(new Attempts, 1000), 1001);
    $again = $policy->failed($locked, 1100);

    expect($again)->toEqual($locked);
});

it('forgets the failures after a success but not the history of lockouts', function (): void {
    $policy = new ThrottlePolicy(2, 900, 600);
    $attempts = $policy->failed($policy->failed(new Attempts, 1000), 1001);
    $after = $policy->succeeded(new Attempts(1, 1500, 0, $attempts->lockouts));

    expect($after->failures)->toBe(0)->and($after->windowStart)->toBe(0)->and($after->lockedUntil)->toBe(0)->and($after->lockouts)->toBe(1);
});

it('reads a stored record, and a damaged one as nothing', function (): void {
    $attempts = new Attempts(2, 100, 500, 3);

    expect(Attempts::fromArray($attempts->toArray()))->toEqual($attempts)
        ->and(Attempts::fromArray('junk'))->toEqual(new Attempts)
        ->and(Attempts::fromArray(['failures' => -4, 'lockouts' => 'x']))->toEqual(new Attempts);
});
