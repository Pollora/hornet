<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;

it('comes back from what it stored, whole', function (): void {
    $snapshot = new ScanSnapshot(
        new DateTimeImmutable('2026-11-25 10:00:00 UTC'),
        ['core-integrity' => Finding::fail('core-integrity', Severity::High, 'Two files differ.', ['Changed: a.php'], 'Look at them.'), 'other' => Finding::unknown('other', 'later')],
        ['core-integrity' => new Pins('7.1.3 (en_US)', ['wp-includes/l10n.php' => md5('x')])],
    );

    $back = ScanSnapshot::fromArray(json_decode((string) json_encode($snapshot->toArray()), true));

    expect($back)->toEqual($snapshot)->and($back?->finding('core-integrity')?->severity)->toBe(Severity::High);
});

it('leaves out what was cut or edited in the database, and refuses what is not a scan', function (): void {
    $back = ScanSnapshot::fromArray([
        'at' => 1795600000,
        'findings' => [
            'good' => ['check' => 'good', 'outcome' => 'pass', 'severity' => 'info', 'summary' => 's', 'details' => ['one', 3]],
            'no-outcome' => ['check' => 'x', 'outcome' => 'burning', 'severity' => 'high', 'summary' => 's'],
            'no-summary' => ['check' => 'x', 'outcome' => 'pass', 'severity' => 'info'],
            'not-an-array' => 'text',
        ],
        'pins' => ['a' => ['scope' => 'x', 'hashes' => ['p' => 'h', 'q' => 4]], 'b' => 'text', 'c' => ['scope' => 'x']],
    ]);

    expect(array_keys($back?->findings ?? []))->toBe(['good'])
        ->and($back?->finding('good')?->details)->toBe(['one'])
        ->and($back?->finding('good')?->outcome)->toBe(Outcome::Pass)
        ->and($back?->pins('a')?->hashes)->toBe(['p' => 'h'])
        ->and($back?->pins('b'))->toBeNull()
        ->and(ScanSnapshot::fromArray('text'))->toBeNull()
        ->and(ScanSnapshot::fromArray(['at' => 'yesterday', 'findings' => []]))->toBeNull()
        ->and(ScanSnapshot::fromArray(['at' => 1, 'findings' => 'none']))->toBeNull();
});
