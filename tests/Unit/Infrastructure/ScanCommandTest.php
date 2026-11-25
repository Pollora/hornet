<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Infrastructure\WpCli\ScanCommand;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeCoreChecksums;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStore;
use Pollora\Hornet\Tests\Fixtures\RecordingOutput;

function scanCommand(ArrayFileSystem $files, RecordingOutput $output, ?InMemoryScanStore $store = null, bool $off = false): ScanCommand
{
    $scanner = $off ? null : new Scanner([new CoreIntegrityTask(new FakeCoreChecksums(scanOfficial()), $files, '7.1.3', 'en_US')], $store ?? new InMemoryScanStore, new FakeClock);

    return new ScanCommand(fn (): ?Scanner => $scanner, $output);
}

function scanOfficial(): array
{
    return (new ArrayFileSystem(['index.php' => 'index', 'wp-includes/version.php' => 'version']))->official();
}

function scanned(): ArrayFileSystem
{
    return new ArrayFileSystem(['index.php' => 'index', 'wp-includes/version.php' => 'version']);
}

it('scans, says all is well, and keeps the result for the audit', function (): void {
    $output = new RecordingOutput;
    $store = new InMemoryScanStore;

    scanCommand(scanned(), $output, $store)();

    expect($output->successes)->toBe(['Nothing found.'])
        ->and($output->rows[0]['status'])->toBe('pass')
        ->and($output->rows[0]['check'])->toBe('core-integrity')
        ->and($store->saves)->toBe(1);
});

it('lists what it found with the files, and fails on a high finding by default', function (): void {
    $files = scanned();
    $files->files['wp-includes/shell.php'] = 'x';
    $output = new RecordingOutput;

    expect(fn () => scanCommand($files, $output)())->toThrow(RuntimeException::class, 'high or above');

    expect($output->rows[0]['status'])->toBe('open')->and($output->rows[0]['severity'])->toBe('high')
        ->and($output->lines)->toContain('  Not part of WordPress: wp-includes/shell.php')
        ->and($output->warnings)->toBe(['1 open finding.'])
        ->and(implode("\n", $output->lines))->toContain('core-integrity: Look at each file');
});

it('fails at the threshold it is given, and never with none', function (): void {
    $files = scanned();
    $files->files['wp-admin/notes.txt'] = 'x';

    expect(fn () => scanCommand($files, new RecordingOutput)())->not->toThrow(RuntimeException::class);
    expect(fn () => scanCommand($files, new RecordingOutput)([], ['fail-on' => 'low']))->toThrow(RuntimeException::class, 'low or above');
    expect(fn () => scanCommand($files, new RecordingOutput)([], ['fail-on' => 'none']))->not->toThrow(RuntimeException::class);
});

it('prints the findings as JSON', function (): void {
    $output = new RecordingOutput;

    scanCommand(scanned(), $output)([], ['format' => 'json']);

    $json = json_decode($output->lines[0], true);

    expect($json['counts']['pass'])->toBe(1)
        ->and($json['scanned_at'])->toBe('2026-11-25T10:00:00+00:00')
        ->and($json['findings'][0]['check'])->toBe('core-integrity')
        ->and($output->rows)->toBe([]);
});

it('says an unverified check is unverified, and does not call it a pass', function (): void {
    $files = scanned();
    $files->unlistable = ['wp-admin'];
    $output = new RecordingOutput;

    scanCommand($files, $output)();

    expect($output->rows[0]['status'])->toBe('unknown')->and($output->rows[0]['severity'])->toBe('-')
        ->and($output->successes)->toBe(['Nothing found.']);
});

it('pins the allowed files again when asked', function (): void {
    $files = scanned();
    $files->files['wp-includes/version.php'] = 'patched';
    $store = new InMemoryScanStore;
    $official = new FakeCoreChecksums(scanOfficial());
    $scanner = new Scanner([new CoreIntegrityTask($official, $files, '7.1.3', 'en_US', ['wp-includes/version.php'])], $store, new FakeClock);

    (new ScanCommand(fn (): Scanner => $scanner, new RecordingOutput))();
    $files->files['wp-includes/version.php'] = 'patched again';
    (new ScanCommand(fn (): Scanner => $scanner, new RecordingOutput))([], ['repin' => true]);

    expect($store->snapshot?->pins('core-integrity')?->hashes)->toBe(['wp-includes/version.php' => md5('patched again')]);
});

it('does nothing when Hornet is off or the files cannot be read', function (): void {
    expect(fn () => scanCommand(scanned(), new RecordingOutput, off: true)())->toThrow(RuntimeException::class, 'nothing was scanned');
});

it('refuses a format or a severity it does not know', function (): void {
    expect(fn () => scanCommand(scanned(), new RecordingOutput)([], ['format' => 'xml']))->toThrow(RuntimeException::class, 'Unknown format')
        ->and(fn () => scanCommand(scanned(), new RecordingOutput)([], ['fail-on' => 'severe']))->toThrow(RuntimeException::class, 'Unknown severity');
});

it('does not fail on a finding the owner set aside, and shows it as ignored', function (): void {
    $files = scanned();
    $files->files['wp-includes/shell.php'] = 'x';
    $output = new RecordingOutput;
    $scanner = new Scanner([new CoreIntegrityTask(new FakeCoreChecksums(scanOfficial()), $files, '7.1.3', 'en_US')], new InMemoryScanStore, new FakeClock, ['core-integrity' => 'Reviewed on 2026-11-20.']);

    (new ScanCommand(fn (): Scanner => $scanner, $output))();

    expect($output->rows[0]['status'])->toBe('ignored')->and($output->successes)->toBe(['Nothing found.']);
});
