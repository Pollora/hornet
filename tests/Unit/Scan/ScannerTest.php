<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStore;

function fixedTask(string $id, TaskResult|Throwable $result): ScanTask
{
    return new class($id, $result) implements ScanTask
    {
        public ?Pins $received = null;

        public ?bool $repin = null;

        public function __construct(private string $id, private TaskResult|Throwable $result) {}

        public function id(): string
        {
            return $this->id;
        }

        public function run(?Pins $pinned, bool $repin): TaskResult
        {
            $this->received = $pinned;
            $this->repin = $repin;

            return $this->result instanceof Throwable ? throw $this->result : $this->result;
        }
    };
}

it('runs every task and keeps what they found with the time', function (): void {
    $store = new InMemoryScanStore;
    $pins = new Pins('x', ['a' => 'b']);
    $scanner = new Scanner([fixedTask('one', new TaskResult(Finding::pass('one', 'fine'), $pins)), fixedTask('two', new TaskResult(Finding::unknown('two', 'later')))], $store, new FakeClock('2026-11-25 10:00:00'));

    $snapshot = $scanner->run();

    expect($scanner->tasks())->toBe(['one', 'two'])
        ->and($snapshot->finding('one')?->outcome)->toBe(Outcome::Pass)
        ->and($snapshot->finding('two')?->outcome)->toBe(Outcome::Unknown)
        ->and($snapshot->pins('one'))->toBe($pins)
        ->and($snapshot->pins('two'))->toBeNull()
        ->and($snapshot->at->format('c'))->toBe('2026-11-25T10:00:00+00:00')
        ->and($store->saves)->toBe(3)
        ->and($store->snapshot)->toBe($snapshot);
});

it('gives a task the pins of the last scan, and the wish to pin again', function (): void {
    $pins = new Pins('7.1.3', ['a' => 'b']);
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20'), [], ['one' => $pins]));
    $task = fixedTask('one', new TaskResult(Finding::pass('one', 'fine')));

    (new Scanner([$task], $store, new FakeClock))->run(true);

    expect($task->received)->toBe($pins)->and($task->repin)->toBeTrue();
});

it('reports a task that crashed and goes on with the others, keeping its pins', function (): void {
    $pins = new Pins('7.1.3', ['a' => 'b']);
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20'), [], ['one' => $pins]));
    $scanner = new Scanner([fixedTask('one', new RuntimeException('secret detail')), fixedTask('two', new TaskResult(Finding::pass('two', 'fine')))], $store, new FakeClock);

    $snapshot = $scanner->run();

    expect($snapshot->finding('one')?->outcome)->toBe(Outcome::Unknown)
        ->and($snapshot->finding('one')?->summary)->toBe('The scan could not run: RuntimeException')
        ->and($snapshot->finding('two')?->outcome)->toBe(Outcome::Pass)
        ->and($snapshot->pins('one'))->toBe($pins);
});

it('keeps a failure that an earlier scan found when the next one could not look', function (): void {
    $failed = Finding::fail('one', Severity::High, 'A file differs.', ['Changed: a.php'], 'Look at it.');
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20'), ['one' => $failed]));
    $scanner = new Scanner([fixedTask('one', new TaskResult(Finding::unknown('one', 'WordPress.org did not answer.')))], $store, new FakeClock('2026-11-25 10:00:00'));

    $finding = $scanner->run()->finding('one');

    expect($finding?->outcome)->toBe(Outcome::Fail)
        ->and($finding?->severity)->toBe(Severity::High)
        ->and($finding?->summary)->toBe('A file differs.')
        ->and($finding?->fix)->toBe('Look at it.')
        ->and($finding?->details)->toBe(['Changed: a.php', 'Not verified again on 2026-11-25 10:00 UTC: WordPress.org did not answer.']);
});

it('tells only the latest reason when a failure is kept again and again', function (): void {
    $failed = Finding::fail('one', Severity::High, 'A file differs.', ['Changed: a.php']);
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20'), ['one' => $failed]));
    $clock = new FakeClock('2026-11-25 10:00:00');
    $scanner = new Scanner([fixedTask('one', new TaskResult(Finding::unknown('one', 'first reason')))], $store, $clock);

    $scanner->run();
    $clock->advance('PT1H');
    $again = (new Scanner([fixedTask('one', new TaskResult(Finding::unknown('one', 'second reason')))], $store, $clock))->run()->finding('one');

    expect($again?->details)->toBe(['Changed: a.php', 'Not verified again on 2026-11-25 11:00 UTC: second reason']);
});

it('does not keep what was fine: a pass followed by a scan that could not look is not known', function (): void {
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20'), ['one' => Finding::pass('one', 'fine')]));
    $scanner = new Scanner([fixedTask('one', new TaskResult(Finding::unknown('one', 'down')))], $store, new FakeClock);

    expect($scanner->run()->finding('one')?->outcome)->toBe(Outcome::Unknown);
});

it('keeps a failure when the task crashed too', function (): void {
    $failed = Finding::fail('one', Severity::Medium, 'A file is gone.');
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20'), ['one' => $failed]));
    $scanner = new Scanner([fixedTask('one', new RuntimeException('boom'))], $store, new FakeClock);

    $finding = $scanner->run()->finding('one');

    expect($finding?->outcome)->toBe(Outcome::Fail)->and($finding?->details[0])->toContain('The scan could not run: RuntimeException');
});

it('sets aside, on the reason the owner wrote, what the owner chose to live with', function (): void {
    $scanner = new Scanner([fixedTask('one', new TaskResult(Finding::fail('one', Severity::High, 'A file differs.')))], new InMemoryScanStore, new FakeClock, ['one' => 'Reviewed on 2026-11-20.']);

    $report = $scanner->report($scanner->run());

    expect($report->open())->toBe([])
        ->and($report->counts()['ignored'])->toBe(1)
        ->and($report->findings[0]->ignoredReason)->toBe('Reviewed on 2026-11-20.');
});

it('says which tasks stopped on their budget, and keeps it in what is stored', function (): void {
    $store = new InMemoryScanStore;
    $scanner = new Scanner([fixedTask('one', new TaskResult(Finding::unknown('one', 'half'), new Pins('cursor:a', []), true)), fixedTask('two', new TaskResult(Finding::pass('two', 'fine')))], $store, new FakeClock);

    $snapshot = $scanner->run();

    expect($snapshot->unfinished)->toBe(['one'])
        ->and(ScanSnapshot::fromArray(json_decode((string) json_encode($snapshot->toArray()), true))?->unfinished)->toBe(['one'])
        ->and(ScanSnapshot::fromArray(['at' => 1, 'findings' => [], 'unfinished' => ['a', 4]])?->unfinished)->toBe(['a'])
        ->and(ScanSnapshot::fromArray(['at' => 1, 'findings' => []])?->unfinished)->toBe([]);
});

it('runs only the tasks it is told to on a continuation, and keeps what the others found', function (): void {
    $one = fixedTask('one', new TaskResult(Finding::pass('one', 'fresh one')));
    $two = fixedTask('two', new TaskResult(Finding::pass('two', 'fresh two')));
    $pins = new Pins('7.1.5', ['a' => 'b']);
    $before = new ScanSnapshot(new DateTimeImmutable('2026-11-20'), ['one' => Finding::fail('one', Severity::High, 'old one'), 'two' => Finding::pass('two', 'old two')], ['one' => $pins], ['two']);
    $store = new InMemoryScanStore($before);

    $snapshot = (new Scanner([$one, $two], $store, new FakeClock))->run(false, ['two']);

    expect($snapshot->finding('one')?->summary)->toBe('old one')
        ->and($snapshot->finding('two')?->summary)->toBe('fresh two')
        ->and($snapshot->pins('one'))->toBe($pins)
        ->and($one->received)->toBeNull()
        ->and($two->repin)->toBeFalse()
        ->and($snapshot->unfinished)->toBe([]);
});

it('runs a task that has no earlier result even when it is not asked for', function (): void {
    $new = fixedTask('new', new TaskResult(Finding::pass('new', 'first time')));
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20'), ['old' => Finding::pass('old', 'fine')]));

    $snapshot = (new Scanner([fixedTask('old', new TaskResult(Finding::pass('old', 'again'))), $new], $store, new FakeClock))->run(false, ['old']);

    expect($snapshot->finding('new')?->summary)->toBe('first time');
});

it('keeps an unfinished task unfinished when the continuation does not touch it', function (): void {
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20'), ['one' => Finding::unknown('one', 'half'), 'two' => Finding::pass('two', 'x')], [], ['one']));

    $snapshot = (new Scanner([fixedTask('one', new TaskResult(Finding::pass('one', 'x'))), fixedTask('two', new TaskResult(Finding::pass('two', 'y')))], $store, new FakeClock))->run(false, ['two']);

    expect($snapshot->unfinished)->toBe(['one']);
});

it('saves after each task, with the old date until the last one is done, so that a run that dies loses only the task it was in', function (): void {
    $store = new InMemoryScanStore(new ScanSnapshot(new DateTimeImmutable('2026-11-20 10:00:00 UTC'), ['one' => Finding::pass('one', 'old one'), 'two' => Finding::pass('two', 'old two')]));
    $scanner = new Scanner([fixedTask('one', new TaskResult(Finding::pass('one', 'new one'))), fixedTask('two', new TaskResult(Finding::pass('two', 'new two')))], $store, new FakeClock('2026-11-25 10:00:00'));

    $scanner->run();
    [$afterOne, $afterTwo, $final] = $store->history;

    // After the first task: its result is new, the other is still the old one, and the date is still the old one
    expect($store->saves)->toBe(3)
        ->and($afterOne->finding('one')?->summary)->toBe('new one')
        ->and($afterOne->finding('two')?->summary)->toBe('old two')
        ->and($afterOne->at->format('Y-m-d'))->toBe('2026-11-20')
        ->and($afterTwo->at->format('Y-m-d'))->toBe('2026-11-20')
        ->and($final->at->format('Y-m-d'))->toBe('2026-11-25');
});

it('says which task it is about to run, before each one', function (): void {
    $seen = [];
    $scanner = new Scanner([fixedTask('one', new TaskResult(Finding::pass('one', 'fine'))), fixedTask('two', new TaskResult(Finding::pass('two', 'fine')))], new InMemoryScanStore, new FakeClock, [], static function (string $id) use (&$seen): void {
        $seen[] = $id;
    });

    $scanner->run(false, ['two']);
    $scanner->run();

    // On a continuation, a task that is not run again is not announced
    expect($seen)->toBe(['one', 'two', 'one', 'two']);
});

it('starts no task past its time box, and leaves them for the run that goes on', function (): void {
    $clock = new FakeClock('2026-11-25 10:00:00');
    $store = new InMemoryScanStore;
    $slow = new class($clock) implements ScanTask
    {
        public function __construct(private FakeClock $clock) {}

        public function id(): string
        {
            return 'slow';
        }

        public function run(?Pins $pinned, bool $repin): TaskResult
        {
            $this->clock->advance('PT30S');

            return new TaskResult(Finding::pass('slow', 'done'));
        }
    };
    $tasks = [$slow, fixedTask('later', new TaskResult(Finding::pass('later', 'done')))];
    $first = (new Scanner($tasks, $store, $clock, timeBox: 10))->run();

    expect($first->unfinished)->toBe(['later'])->and($first->finding('later'))->toBeNull();

    $second = (new Scanner($tasks, $store, $clock, timeBox: 10))->run(false, $first->unfinished);

    expect($second->unfinished)->toBe([])->and($second->finding('later')?->summary)->toBe('done');
});
