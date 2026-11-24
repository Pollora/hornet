<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;

function sampleReport(): Report
{
    return new Report([
        Finding::pass('a', 'fine'),
        Finding::fail('b', Severity::Low, 'low'),
        Finding::fail('c', Severity::High, 'high'),
        Finding::fail('d', Severity::Medium, 'accepted')->ignoredFor('We know.'),
        Finding::unknown('e', 'cannot tell'),
    ]);
}

it('lists the open findings, the most serious first, without the ignored ones', function (): void {
    expect(array_map(static fn (Finding $finding): string => $finding->checkId, sampleReport()->open()))->toBe(['c', 'b']);
});

it('says how bad the worst open finding is', function (): void {
    expect(sampleReport()->worst())->toBe(Severity::High)
        ->and((new Report([Finding::pass('a', 'fine')]))->worst())->toBeNull();
});

it('compares the open findings to a threshold', function (): void {
    expect(sampleReport()->hasOpenAtLeast(Severity::High))->toBeTrue()
        ->and(sampleReport()->hasOpenAtLeast(Severity::Critical))->toBeFalse()
        // The ignored medium does not count
        ->and((new Report([Finding::fail('d', Severity::Medium, 'x')->ignoredFor('why')]))->hasOpenAtLeast(Severity::Medium))->toBeFalse();
});

it('counts each verdict once', function (): void {
    expect(sampleReport()->counts())->toBe(['total' => 5, 'pass' => 1, 'open' => 2, 'ignored' => 1, 'unknown' => 1]);
});

it('finds a finding by check', function (): void {
    expect(sampleReport()->find('c')?->summary)->toBe('high')
        ->and(sampleReport()->find('zzz'))->toBeNull();
});

it('keeps why a finding was set aside', function (): void {
    $finding = Finding::fail('d', Severity::Medium, 'x')->ignoredFor('We know.');

    expect($finding->isIgnored())->toBeTrue()
        ->and($finding->isOpen())->toBeFalse()
        ->and($finding->toArray()['ignored'])->toBe('We know.');
});

it('orders the severities', function (): void {
    expect(Severity::Critical->atLeast(Severity::High))->toBeTrue()
        ->and(Severity::Low->atLeast(Severity::Medium))->toBeFalse()
        ->and(Severity::Info->rank())->toBe(0);
});
