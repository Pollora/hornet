<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Notification\RunLock;
use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStateStore;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStore;

/**
 * A task that answers what it is told, moves the clock by what it takes, and counts its runs.
 */
function mutAppTask(string $id, ?Closure $answer = null, ?FakeClock $clock = null, string $takes = 'PT0S'): ScanTask
{
    return new class($id, $answer, $clock, $takes) implements ScanTask
    {
        /** @var list<array{?Pins, bool}> */
        public array $runs = [];

        public function __construct(private readonly string $name, private readonly ?Closure $answer, private readonly ?FakeClock $clock, private readonly string $takes) {}

        public function id(): string
        {
            return $this->name;
        }

        public function run(?Pins $pinned, bool $repin): TaskResult
        {
            $this->runs[] = [$pinned, $repin];
            $this->clock?->advance($this->takes);

            return $this->answer instanceof Closure ? ($this->answer)() : new TaskResult(Finding::pass($this->name, 'fresh '.$this->name));
        }
    };
}

function mutAppUnknown(string $id, string $why = 'no answer'): Closure
{
    return static fn (): TaskResult => new TaskResult(Finding::unknown($id, $why));
}

it('keeps what an earlier scan found, and its pins, for the tasks that do not run again', function (): void {
    $a = mutAppTask('a');
    $b = mutAppTask('b');
    $earlier = new ScanSnapshot(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), ['a' => Finding::fail('a', Severity::High, 'old a'), 'b' => Finding::fail('b', Severity::High, 'old b')], ['a' => new Pins('scope-a', ['x' => '1']), 'b' => new Pins('scope-b', ['y' => '2'])]);

    $snapshot = (new Scanner([$a, $b], new InMemoryScanStore($earlier), new FakeClock))->run(false, ['a']);

    expect($snapshot->findings['b']->summary)->toBe('old b')
        ->and($snapshot->pins('b')?->scope)->toBe('scope-b')
        ->and($snapshot->findings['a']->summary)->toBe('fresh a')
        // The task that ran gave no pins: the old ones are not left behind
        ->and($snapshot->pins('a'))->toBeNull()
        ->and($b->runs)->toBe([])
        ->and($a->runs[0][0]?->scope)->toBe('scope-a')
        ->and($a->runs[0][1])->toBeFalse()
        ->and($snapshot->unfinished)->toBe([]);
});

it('runs a task it was not asked for when nothing is known of it yet', function (): void {
    $a = mutAppTask('a');
    $b = mutAppTask('b');

    $snapshot = (new Scanner([$a, $b], new InMemoryScanStore, new FakeClock))->run(true, ['a']);

    expect($b->runs)->toHaveCount(1)->and($b->runs[0][1])->toBeTrue()->and(array_keys($snapshot->findings))->toBe(['a', 'b']);
});

it('still calls a task unfinished when it was and is not run again, and no longer once it finishes', function (): void {
    $earlier = new ScanSnapshot(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), ['a' => Finding::pass('a', 'old'), 'b' => Finding::pass('b', 'old')], [], ['a', 'b']);

    $snapshot = (new Scanner([mutAppTask('a'), mutAppTask('b')], new InMemoryScanStore($earlier), new FakeClock))->run(false, ['a']);

    expect($snapshot->unfinished)->toBe(['b']);
});

it('keeps the pins a task gives, and says which tasks are not finished', function (): void {
    $partial = mutAppTask('a', static fn (): TaskResult => new TaskResult(Finding::unknown('a', 'half'), new Pins('cursor', ['z' => '3']), unfinished: true));

    $snapshot = (new Scanner([$partial], new InMemoryScanStore, new FakeClock))->run();

    expect($snapshot->pins('a')?->hashes)->toBe(['z' => '3'])->and($snapshot->unfinished)->toBe(['a']);
});

it('starts no further task once the time box is spent, but always one', function (): void {
    $clock = new FakeClock;
    $a = mutAppTask('a', null, $clock, 'PT10S');
    $b = mutAppTask('b');
    $c = mutAppTask('c');
    $earlier = new ScanSnapshot(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), ['b' => Finding::pass('b', 'old b')]);

    $snapshot = (new Scanner([$a, $b, $c], new InMemoryScanStore($earlier), $clock, [], null, 10))->run();

    // Ten seconds is the whole of the box: at the tenth, nothing more starts
    expect($b->runs)->toBe([])->and($c->runs)->toBe([])
        ->and($snapshot->unfinished)->toBe(['b', 'c'])
        ->and($snapshot->findings['b']->summary)->toBe('old b')
        // What was not looked at again does not look fresh
        ->and($snapshot->at->format('Y-m-d'))->toBe('2026-11-01');
});

it('goes on while the time box lasts', function (): void {
    $clock = new FakeClock;
    $b = mutAppTask('b');

    $snapshot = (new Scanner([mutAppTask('a', null, $clock, 'PT9S'), $b], new InMemoryScanStore, $clock, [], null, 10))->run();

    expect($b->runs)->toHaveCount(1)->and($snapshot->unfinished)->toBe([]);
});

it('runs one task even with a box of nothing', function (): void {
    $a = mutAppTask('a');
    $b = mutAppTask('b');

    (new Scanner([$a, $b], new InMemoryScanStore, new FakeClock, [], null, 0))->run();

    expect($a->runs)->toHaveCount(1)->and($b->runs)->toBe([]);
});

it('dates a complete scan of now, and saves after each task under the old date', function (): void {
    $clock = new FakeClock('2026-11-25 10:00:00');
    $earlier = new ScanSnapshot(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), []);
    $store = new InMemoryScanStore($earlier);

    (new Scanner([mutAppTask('a'), mutAppTask('b')], $store, $clock))->run();

    expect(array_map(static fn (ScanSnapshot $saved): string => $saved->at->format('Y-m-d'), $store->history))->toBe(['2026-11-01', '2026-11-01', '2026-11-25']);
});

it('says before each task which one it is at', function (): void {
    $told = [];

    (new Scanner([mutAppTask('a'), mutAppTask('b')], new InMemoryScanStore, new FakeClock, [], function (string $id) use (&$told): void {
        $told[] = $id;
    }))->run();

    expect($told)->toBe(['a', 'b']);
});

it('reports a task that crashes as not verified, by its class only, and keeps its pins', function (): void {
    $crash = mutAppTask('a', static fn (): TaskResult => throw new RuntimeException('password=hunter2'));
    $earlier = new ScanSnapshot(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), [], ['a' => new Pins('s', ['x' => '1'])]);

    $snapshot = (new Scanner([$crash], new InMemoryScanStore($earlier), new FakeClock))->run();

    expect($snapshot->findings['a']->outcome)->toBe(Outcome::Unknown)
        ->and($snapshot->findings['a']->summary)->toBe('The scan could not run: RuntimeException')
        ->and($snapshot->pins('a')?->hashes)->toBe(['x' => '1']);
});

it('does not let a look that failed erase a failure, and tells only the last reason', function (): void {
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), ['a' => Finding::fail('a', Severity::Critical, 'planted file', ['wp-includes/x.php'], 'remove it')]));
    $clock = new FakeClock('2026-11-25 10:00:00');

    (new Scanner([mutAppTask('a', mutAppUnknown('a', 'timeout'))], $store, $clock))->run();
    $clock->advance('P1D');
    $finding = (new Scanner([mutAppTask('a', mutAppUnknown('a', 'refused'))], $store, $clock))->run()->findings['a'];

    expect($finding->outcome)->toBe(Outcome::Fail)
        ->and($finding->severity)->toBe(Severity::Critical)
        ->and($finding->summary)->toBe('planted file')
        ->and($finding->fix)->toBe('remove it')
        ->and($finding->details)->toBe(['wp-includes/x.php', 'Not verified again on 2026-11-26 10:00 UTC: refused']);
});

it('lets an unknown replace what was not a failure', function (?Finding $before): void {
    $store = new InMemoryScanStore($before instanceof Finding ? new ScanSnapshot(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), ['a' => $before]) : null);

    $finding = (new Scanner([mutAppTask('a', mutAppUnknown('a'))], $store, new FakeClock))->run()->findings['a'];

    expect($finding->outcome)->toBe(Outcome::Unknown)->and($finding->summary)->toBe('no answer');
})->with(['nothing before' => [null], 'a pass before' => [Finding::pass('a', 'fine')], 'an unknown before' => [Finding::unknown('a', 'older')]]);

it('lets a look that succeeds replace the failure of before', function (): void {
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), ['a' => Finding::fail('a', Severity::High, 'planted file')]));

    expect((new Scanner([mutAppTask('a')], $store, new FakeClock))->run()->findings['a']->outcome)->toBe(Outcome::Pass);
});

it('sets aside in its report only the findings the owner ignored, in a plain list', function (): void {
    $scanner = new Scanner([], new InMemoryScanStore, new FakeClock, ['b' => 'known patch']);
    $report = $scanner->report(new ScanSnapshot(new DateTimeImmutable, ['a' => Finding::fail('a', Severity::High, 'x'), 'b' => Finding::fail('b', Severity::High, 'y')]));

    expect(array_keys($report->findings))->toBe([0, 1])
        ->and($report->findings[0]->ignoredReason)->toBeNull()
        ->and($report->findings[1]->ignoredReason)->toBe('known patch');
});

/**
 * @return array{ScanJob, InMemoryScanStateStore, RunLock}
 */
function mutAppJob(?Scanner $scanner, FakeClock $clock, ?Closure $seen = null, ?RunLock $lock = null): array
{
    $state = new InMemoryScanStateStore;
    $lock ??= new ArrayLock;
    $job = new ScanJob(static function (Closure $progress) use ($scanner, $seen, $state): ?Scanner {
        $progress('first');
        $seen?->__invoke($state->get());

        return $scanner;
    }, $lock, $state, $clock, static fn (): array => ['left']);

    return [$job, $state, $lock];
}

it('tells which task runs and since when, then goes back to idle and lets the lock go', function (): void {
    $clock = new FakeClock;
    $seen = null;
    [$job, $state, $lock] = mutAppJob(new Scanner([mutAppTask('a')], new InMemoryScanStore, $clock), $clock, function (ScanState $now) use (&$seen): void {
        $seen = $now->toArray();
    });

    $unfinished = $job->run();

    expect($seen)->toBe(['status' => ScanState::RUNNING, 'task' => 'first', 'since' => $clock->now()->getTimestamp()])
        ->and($state->get()->status)->toBe(ScanState::IDLE)
        ->and($unfinished)->toBeFalse()
        ->and($lock->acquire())->toBeTrue()
        ->and($job->unfinished())->toBe(['left']);
});

it('says that a run that goes on is wanted when a task stopped short', function (): void {
    $clock = new FakeClock;
    $partial = mutAppTask('a', static fn (): TaskResult => new TaskResult(Finding::unknown('a', 'half'), unfinished: true));
    [$job] = mutAppJob(new Scanner([$partial], new InMemoryScanStore, $clock), $clock);

    expect($job->run())->toBeTrue();
});

it('says nothing goes on when there is no scanner, and still lets the lock go', function (): void {
    $clock = new FakeClock;
    [$job, , $lock] = mutAppJob(null, $clock);

    expect($job->run())->toBeFalse()->and($lock->acquire())->toBeTrue();
});

it('lets the lock go and goes idle when the scan blows up', function (): void {
    $state = new InMemoryScanStateStore;
    $lock = new ArrayLock;
    $job = new ScanJob(static fn (): Scanner => throw new RuntimeException('boom'), $lock, $state, new FakeClock, static fn (): array => []);

    expect(fn () => $job->run())->toThrow(RuntimeException::class)
        ->and($state->get()->status)->toBe(ScanState::IDLE)
        ->and($lock->acquire())->toBeTrue();
});

it('does not run while another run holds the lock', function (): void {
    $clock = new FakeClock;
    $lock = new ArrayLock;
    $lock->acquire();
    $task = mutAppTask('a');
    [$job] = mutAppJob(new Scanner([$task], new InMemoryScanStore, $clock), $clock, null, $lock);

    expect($job->run())->toBeNull()->and($task->runs)->toBe([]);
});

it('says that a run waits for the cron', function (): void {
    $clock = new FakeClock;
    [$job] = mutAppJob(null, $clock);

    $job->queued();

    expect($job->state()->toArray())->toBe(['status' => ScanState::QUEUED, 'task' => null, 'since' => $clock->now()->getTimestamp()]);
});

it('runs the scan job without accepting changed files anew', function (): void {
    $clock = new FakeClock;
    $task = mutAppTask('a');
    $scanner = new Scanner([$task], new InMemoryScanStore, $clock);

    (new ScanJob(static fn (): Scanner => $scanner, new ArrayLock, new InMemoryScanStateStore, $clock, static fn (): array => []))->run();

    expect($task->runs[0][1])->toBeFalse();
});

it('keeps nothing about a task that was never reached', function (): void {
    $clock = new FakeClock;

    $snapshot = (new Scanner([mutAppTask('a', null, $clock, 'PT20S'), mutAppTask('b')], new InMemoryScanStore, $clock, [], null, 10))->run();

    expect(array_keys($snapshot->findings))->toBe(['a'])->and($snapshot->pins)->toBe([])->and($snapshot->unfinished)->toBe(['b']);
});
