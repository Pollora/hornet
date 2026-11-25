<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Checks\ScanResultCheck;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\Samples;

function resultCheck(): ScanResultCheck
{
    return new ScanResultCheck('core-integrity', 'WordPress core files');
}

it('says it does not know when the site was never scanned, or the scan did not cover this', function (): void {
    $never = resultCheck()->run(new FakeEnvironment);
    $other = resultCheck()->run(new FakeEnvironment(scan: new ScanSnapshot(new DateTimeImmutable('2026-11-24'), ['plugins' => Finding::pass('plugins', 'fine')])));

    expect($never->outcome)->toBe(Outcome::Unknown)->and($never->summary)->toContain('wp hornet scan')
        ->and($other->outcome)->toBe(Outcome::Unknown)->and($other->summary)->toContain('did not cover');
});

it('shows what the last scan found, as it found it', function (): void {
    $failed = Finding::fail('core-integrity', Severity::High, 'A file differs.', ['Changed: a.php'], 'Look.');

    $finding = resultCheck()->run(new FakeEnvironment(scan: new ScanSnapshot(new DateTimeImmutable('2026-11-24'), ['core-integrity' => $failed])));

    expect($finding)->toEqual($failed)
        ->and(resultCheck()->id())->toBe('core-integrity')
        ->and(resultCheck()->label())->toBe('WordPress core files')
        ->and(resultCheck()->run(new FakeEnvironment(scan: Samples::cleanScan()))->outcome)->toBe(Outcome::Pass);
});

it('does not let an old clean result stand for the files today, and keeps a failure however old', function (): void {
    $old = Samples::cleanScan('2026-11-10 10:00:00');
    $stale = resultCheck()->run(new FakeEnvironment(scan: $old));
    $oldFailure = new ScanSnapshot(new DateTimeImmutable('2026-11-10 UTC'), ['core-integrity' => Finding::fail('core-integrity', Severity::High, 'A file differs.')]);

    expect($stale->outcome)->toBe(Outcome::Fail)->and($stale->severity)->toBe(Severity::Low)
        ->and($stale->details)->toBe(['Last scan: 2026-11-10 10:00 UTC'])
        ->and(resultCheck()->run(new FakeEnvironment(scan: $oldFailure))->severity)->toBe(Severity::High)
        // Seven days exactly is still fresh
        ->and(resultCheck()->run(new FakeEnvironment(scan: Samples::cleanScan('2026-11-17 12:00:00')))->outcome)->toBe(Outcome::Pass);
});
