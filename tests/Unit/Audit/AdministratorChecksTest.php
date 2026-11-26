<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Checks\DormantAdministratorsCheck;
use Pollora\Hornet\Domain\Audit\Checks\NewAdministratorsCheck;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;

/** A moment so many days before the day of the fake site. */
function daysAgo(int $days): int
{
    return (new FakeEnvironment)->now()->getTimestamp() - $days * 86400;
}

function account(int $user, int $since, ?int $seen = null): array
{
    return ['user' => $user, 'since' => $since, 'last_seen' => $seen];
}

it('asks about the accounts that became administrators lately, by number', function (): void {
    $check = new NewAdministratorsCheck;
    $old = account(1, daysAgo(400));
    $finding = $check->run(new FakeEnvironment(administrators: [$old, account(7, daysAgo(3)), account(9, daysAgo(13))]));

    expect($finding->severity)->toBe(Severity::Low)
        ->and($finding->summary)->toStartWith('2 accounts became administrators')
        ->and($finding->details)->toBe(['user #7', 'user #9'])
        ->and($check->run(new FakeEnvironment(administrators: [$old, account(7, daysAgo(15))]))->outcome)->toBe(Outcome::Pass)
        // A new site has nothing to compare with
        ->and($check->run(new FakeEnvironment(administrators: [account(1, daysAgo(2))]))->outcome)->toBe(Outcome::Pass)
        ->and($check->run(new FakeEnvironment(type: 'local', administrators: [$old, account(7, daysAgo(3))]))->outcome)->toBe(Outcome::Pass);
});

it('calls nobody dormant before it has watched for 90 days, and says it does not know', function (): void {
    $check = new DormantAdministratorsCheck;
    $silent = account(5, daysAgo(400));
    $environment = static fn (?int $noted, array $accounts, string $type = 'production'): FakeEnvironment => new FakeEnvironment(type: $type, administrators: $accounts, loginsNotedSince: $noted);

    expect($check->run($environment(null, [$silent]))->outcome)->toBe(Outcome::Unknown)
        ->and($check->run($environment(daysAgo(30), [$silent]))->outcome)->toBe(Outcome::Unknown)
        ->and($check->run($environment(daysAgo(30), [$silent]))->summary)->toContain(gmdate('Y-m-d', daysAgo(30)))
        // Nothing to wait for when everybody is recent or seen
        ->and($check->run($environment(daysAgo(1), [account(1, daysAgo(400), daysAgo(2)), account(2, daysAgo(10))]))->outcome)->toBe(Outcome::Pass)
        ->and($check->run($environment(daysAgo(100), [$silent], 'local'))->outcome)->toBe(Outcome::Pass);

    $finding = $check->run($environment(daysAgo(100), [$silent, account(6, daysAgo(400), daysAgo(95)), account(8, daysAgo(400), daysAgo(89))]));

    expect($finding->outcome)->toBe(Outcome::Fail)
        ->and($finding->severity)->toBe(Severity::Low)
        ->and($finding->details)->toBe(['user #5', 'user #6']);
});
