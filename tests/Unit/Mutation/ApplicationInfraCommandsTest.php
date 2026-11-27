<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Login\Unlocker;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\JournalException;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Domain\Login\AttemptStore;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Infrastructure\WpCli\HornetCommand;
use Pollora\Hornet\Infrastructure\WpCli\JournalCommand;
use Pollora\Hornet\Infrastructure\WpCli\ScanCommand;
use Pollora\Hornet\Infrastructure\WpCli\ServerConfigCommand;
use Pollora\Hornet\Infrastructure\WpCli\UnlockCommand;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStore;
use Pollora\Hornet\Tests\Fixtures\QuietOutput;
use Pollora\Hornet\Tests\Fixtures\RecordingOutput;

/**
 * @return list<Finding>
 */
function mutAppFindings(): array
{
    return [
        Finding::pass('passes', 'fine'),
        Finding::unknown('unsure', 'not verified'),
        Finding::fail('bad', Severity::Medium, 'broken', ['a detail'], 'mend it'),
        Finding::fail('accepted', Severity::Critical, 'known', [], 'never shown')->ignoredFor('why'),
        Finding::fail('nofix', Severity::Low, 'small'),
    ];
}

/**
 * @param  list<Finding>  $findings
 * @param  array<string, mixed>  $assoc
 */
function mutAppStatusCommand(array $findings, array $assoc = []): QuietOutput|RecordingOutput
{
    $output = new RecordingOutput;
    (new HornetCommand(static fn (): Report => new Report($findings), $output))->status([], $assoc);

    return $output;
}

it('lists the open findings and what could not be verified, the open first', function (): void {
    $output = mutAppStatusCommand(mutAppFindings());

    expect($output->format)->toBe('table')
        ->and($output->rows)->toBe([
            ['status' => 'open', 'severity' => 'medium', 'check' => 'bad', 'summary' => 'broken'],
            ['status' => 'open', 'severity' => 'low', 'check' => 'nofix', 'summary' => 'small'],
            ['status' => 'unknown', 'severity' => '-', 'check' => 'unsure', 'summary' => 'not verified'],
        ])
        ->and($output->warnings)->toBe(['5 checks: 1 pass, 2 open, 1 ignored, 1 not verified.'])
        ->and($output->lines)->toBe(['  bad: mend it'])
        ->and($output->successes)->toBe([]);
});

it('lists everything with --all, each with its own status', function (): void {
    $output = mutAppStatusCommand(mutAppFindings(), ['all' => true, 'format' => 'csv']);

    expect($output->format)->toBe('csv')
        ->and(array_column($output->rows, 'status'))->toBe(['pass', 'unknown', 'open', 'ignored', 'open'])
        ->and(array_column($output->rows, 'severity'))->toBe(['-', '-', 'medium', 'critical', 'low']);
});

it('says all is well when nothing is open, without calling the unverified a pass', function (): void {
    $output = mutAppStatusCommand([Finding::pass('a', 'x'), Finding::unknown('b', 'y')]);
    $empty = mutAppStatusCommand([Finding::pass('a', 'x')]);

    expect($output->successes)->toBe(['2 checks: 1 pass, 0 open, 0 ignored, 1 not verified.'])
        ->and($output->warnings)->toBe([])
        ->and($empty->rows)->toBe([]);
});

it('prints one JSON document and nothing else', function (): void {
    $output = mutAppStatusCommand([Finding::fail('a', Severity::Low, 'x/y', [], 'fix')], ['format' => 'json']);

    expect($output->lines)->toHaveCount(1)
        ->and($output->lines[0])->toContain("\n")->toContain('"x/y"')
        ->and(json_decode($output->lines[0], true)['findings'])->toHaveCount(1)
        ->and([$output->successes, $output->warnings])->toBe([[], []]);
});

it('fails from the severity it is given', function (string $failOn, bool $fails): void {
    $run = static fn () => mutAppStatusCommand([Finding::fail('a', Severity::Medium, 'x')], ['fail-on' => $failOn]);

    $fails ? expect($run)->toThrow(RuntimeException::class, 'Open findings at '.$failOn.' or above.') : expect($run)->not->toThrow(RuntimeException::class);
})->with([['info', true], ['medium', true], ['high', false], ['none', false]]);

it('names what it accepts when it refuses', function (): void {
    expect(fn () => mutAppStatusCommand([], ['format' => 'xml']))->toThrow(RuntimeException::class, 'Unknown format "xml": use table, json, csv, yaml.')
        ->and(fn () => mutAppStatusCommand([], ['fail-on' => 'x']))->toThrow(RuntimeException::class, 'Unknown severity "x": use info, low, medium, high, critical or none.');
});

// Scan

/**
 * @param  list<Finding>  $findings
 * @param  array<string, mixed>  $assoc
 */
function mutAppScanCommand(array $findings, array $assoc = [], ?array &$repins = null, ?Closure $refusal = null): RecordingOutput
{
    $repins = [];
    $tasks = [];

    foreach ($findings as $finding) {
        $tasks[] = new class($finding, $repins) implements ScanTask
        {
            public function __construct(private readonly Finding $finding, private array &$repins) {}

            public function id(): string
            {
                return $this->finding->checkId;
            }

            public function run(?Pins $pinned, bool $repin): TaskResult
            {
                $this->repins[] = $repin;

                return new TaskResult($this->finding);
            }
        };
    }

    $output = new RecordingOutput;
    (new ScanCommand(static fn (): Scanner => new Scanner($tasks, new InMemoryScanStore, new FakeClock), $output, $refusal))([], $assoc);

    return $output;
}

it('lists every finding of a scan with its details, and what to do for the open ones', function (): void {
    $output = mutAppScanCommand([...mutAppFindings(), Finding::fail('other', Severity::Low, 'y', [], 'also')], ['fail-on' => 'none'], $repins);

    expect(array_column($output->rows, 'status'))->toBe(['pass', 'unknown', 'open', 'ignored', 'open', 'open'])
        ->and(array_column($output->rows, 'severity'))->toBe(['-', '-', 'medium', 'critical', 'low', 'low'])
        ->and($output->lines)->toBe(['  a detail', '  bad: mend it', '  other: also'])
        ->and($output->warnings)->toBe(['3 open findings.'])
        ->and($output->format)->toBe('table')
        ->and($repins[0])->toBeFalse();
});

it('accepts the allowed files as they are when asked', function (): void {
    mutAppScanCommand([Finding::pass('a', 'x')], ['repin' => true], $repins);

    expect($repins)->toBe([true]);
});

it('does not scan where it is refused', function (): void {
    expect(fn () => mutAppScanCommand([Finding::pass('a', 'x')], [], $repins, static fn (): string => 'Not here.'))->toThrow(RuntimeException::class, 'Not here.')
        ->and(mutAppScanCommand([Finding::pass('a', 'x')], [], $repins, static fn (): ?string => null)->successes)->toBe(['Nothing found.']);
});

it('prints the findings of a scan as readable JSON', function (): void {
    $output = mutAppScanCommand([Finding::pass('a', 'x/y')], ['format' => 'json']);

    expect($output->lines)->toHaveCount(1)->and($output->lines[0])->toContain("\n")->toContain('"x/y"');
});

it('says why nothing was scanned', function (): void {
    $output = new QuietOutput;

    (new ScanCommand(static fn (): ?Scanner => null, $output))();

    expect($output->said)->toBe([['error', 'Hornet is switched off, every scan is disabled, or the WordPress files cannot be read from here: nothing was scanned.']]);
});

// Journal

function mutAppEvent(int $user = 7, string $actor = 'olivier', string $at = '2026-11-25 09:00:00', array $details = [], string $type = 'login.failed'): Event
{
    return new Event(new DateTimeImmutable($at.' UTC'), $type, Source::Web, $user, $actor, 'user:3', '203.0.113.0', $details);
}

function mutAppJournal(InMemoryJournal $journal, RecordingOutput|QuietOutput $output, int $days = 30, ?Closure $drop = null): JournalCommand
{
    return new JournalCommand(static fn (): InMemoryJournal => $journal, $drop ?? static fn () => null, static fn (): int => $days, new FakeClock, $output);
}

it('shows an event with who did it, or its number, or a dash', function (): void {
    $journal = new InMemoryJournal;
    $journal->events = [mutAppEvent(0, ''), mutAppEvent(5, ''), mutAppEvent(details: ['from' => 'a/b'])];
    $output = new RecordingOutput;

    mutAppJournal($journal, $output)->list();

    expect(array_column($output->rows, 'by'))->toBe(['olivier', '#5', '-'])
        ->and($output->rows[0])->toBe(['when' => '2026-11-25 09:00:00', 'type' => 'login.failed', 'source' => 'web', 'by' => 'olivier', 'about' => 'user:3', 'network' => '203.0.113.0', 'details' => '{"from":"a/b"}'])
        ->and($output->rows[1]['details'])->toBe('')
        ->and($output->format)->toBe('table');
});

it('asks the journal for what it was asked', function (): void {
    $journal = new InMemoryJournal;
    $journal->events = [
        mutAppEvent(7, at: '2026-11-20 09:00:00'),
        mutAppEvent(8, at: '2026-11-25 09:00:00'),
        mutAppEvent(7, at: '2026-11-25 09:30:00'),
        mutAppEvent(7, at: '2026-11-25 09:40:00', type: 'user.created'),
    ];
    $run = static function (array $assoc) use ($journal): array {
        $output = new RecordingOutput;
        mutAppJournal($journal, $output)->list([], $assoc);

        return array_column($output->rows, 'when');
    };

    expect($run(['user' => '7']))->toHaveCount(3)
        ->and($run(['user' => 'x']))->toHaveCount(4)
        ->and($run(['days' => '1']))->toHaveCount(3)
        ->and($run(['days' => 'x']))->toHaveCount(4)
        ->and($run(['limit' => '2']))->toHaveCount(2)
        ->and($run(['type' => 'login.']))->toHaveCount(3)
        ->and($run(['type' => 'user.created']))->toHaveCount(1)
        ->and($run(['source' => 'web']))->toHaveCount(4);
});

it('names what it accepts when it refuses a journal query', function (array $assoc, string $why): void {
    expect(fn () => mutAppJournal(new InMemoryJournal, new RecordingOutput)->list([], $assoc))->toThrow(RuntimeException::class, $why);
})->with([
    [['format' => 'xml'], 'Unknown format "xml": use table, json, csv, yaml.'],
    [['source' => 'x'], 'Unknown source'],
    [['type' => 'nope'], 'or a group with a trailing dot (login., '],
]);

it('says when the journal holds nothing, or cannot be read', function (): void {
    $output = new RecordingOutput;
    mutAppJournal(new InMemoryJournal, $output)->list();

    expect($output->warnings)->toBe(['The journal holds no such event.'])->and($output->rows)->toBe([]);

    $broken = new InMemoryJournal;
    $quiet = new QuietOutput;
    $failing = new JournalCommand(static fn (): never => throw new JournalException('The journal table is gone.'), static fn () => null, static fn (): int => 1, new FakeClock, $quiet);
    $failing->list();

    expect($quiet->said)->toBe([['error', 'The journal table is gone.']]);
});

it('forgets what is older than the retention, and says how many', function (): void {
    $journal = new InMemoryJournal;
    $journal->events = [mutAppEvent(at: '2026-10-26 09:59:59'), mutAppEvent(at: '2026-10-26 10:00:00')];
    $output = new RecordingOutput;

    mutAppJournal($journal, $output, 30)->purge();

    expect($output->successes)->toBe(['1 events older than 30 days were forgotten.'])->and($journal->events)->toHaveCount(1);
});

it('removes the journal only when told to', function (): void {
    $dropped = 0;
    $drop = static function () use (&$dropped): void {
        $dropped++;
    };
    $quiet = new QuietOutput;
    mutAppJournal(new InMemoryJournal, $quiet, drop: $drop)->drop();

    expect($dropped)->toBe(0)->and($quiet->said)->toHaveCount(1)->and($quiet->said[0][0])->toBe('error');

    $output = new RecordingOutput;
    mutAppJournal(new InMemoryJournal, $output, drop: $drop)->drop([], ['yes' => true]);

    expect($dropped)->toBe(1)->and($output->successes)->toBe(['The journal was removed.']);
});

// Server rules and unlock

it('prints the rules line by line for the uploads folder given, or the one of the site', function (array $assoc, string $folder): void {
    $output = new RecordingOutput;

    (new ServerConfigCommand(static fn (): string => '/wp-content/uploads', $output))(['nginx'], $assoc);

    expect($output->lines)->not->toBe([])
        ->and(implode("\n", $output->lines))->toContain($folder)
        ->and($output->lines[array_key_last($output->lines)])->not->toBe('')
        ->and(array_filter($output->lines, static fn (string $line): bool => str_contains($line, "\n")))->toBe([]);
})->with([[[], 'wp-content/uploads'], [['uploads' => '/app/files'], 'app/files']]);

it('adds the headers only when asked, and says why it cannot print', function (): void {
    $plain = new RecordingOutput;
    $headers = new RecordingOutput;
    (new ServerConfigCommand(static fn (): string => '/u', $plain))(['nginx']);
    (new ServerConfigCommand(static fn (): string => '/u', $headers))(['nginx'], ['headers' => true]);

    expect(implode("\n", $headers->lines))->toContain('nosniff')->and(implode("\n", $plain->lines))->not->toContain('nosniff');

    $quiet = new QuietOutput;
    (new ServerConfigCommand(static fn (): string => '/u', $quiet))();

    expect($quiet->said)->toHaveCount(1)->and($quiet->said[0][0])->toBe('error');
});

it('says how many addresses were let back in, or that this is not one', function (): void {
    $store = Mockery::mock(AttemptStore::class);
    $store->shouldReceive('forgetAll')->once()->andReturn(3);
    $store->shouldReceive('forget')->once();
    $command = static fn (RecordingOutput|QuietOutput $output): UnlockCommand => new UnlockCommand(static fn (): Unlocker => new Unlocker($store, 's'), $output);

    $all = new RecordingOutput;
    $command($all)([], ['all' => true]);
    $one = new RecordingOutput;
    $command($one)(['203.0.113.7']);
    $bad = new QuietOutput;
    $command($bad)(['nope']);
    $none = new QuietOutput;
    $command($none)([]);

    expect($all->successes)->toBe(['Every address is let back in (3 were locked).'])
        ->and($one->successes)->toBe(['The address is let back in.'])
        ->and($bad->said)->toBe([['error', 'That is not an IP address.']])
        ->and($none->said)->toBe([['error', 'Usage: wp hornet unlock <ip> | wp hornet unlock --all']]);
});
