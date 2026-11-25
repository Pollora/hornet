<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
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
        ->and($store->saves)->toBe(1)
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
