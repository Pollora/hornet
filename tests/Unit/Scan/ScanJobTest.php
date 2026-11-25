<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStateStore;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStore;

function namedTask(string $id, bool $unfinished = false): ScanTask
{
    return new readonly class($id, $unfinished) implements ScanTask
    {
        public function __construct(private string $id, private bool $unfinished) {}

        public function id(): string
        {
            return $this->id;
        }

        public function run(?Pins $pinned, bool $repin): TaskResult
        {
            return new TaskResult(Finding::pass($this->id, 'fine'), null, $this->unfinished);
        }
    };
}

it('tells which task it is at, before each one, and says it is idle at the end', function (): void {
    $state = new InMemoryScanStateStore;
    $job = new ScanJob(static fn (Closure $progress): Scanner => new Scanner([namedTask('core-integrity'), namedTask('plugin-integrity')], new InMemoryScanStore, new FakeClock, [], $progress), new ArrayLock, $state, new FakeClock('2026-11-25 12:00:00'), static fn (): array => []);

    $more = $job->run();

    expect($more)->toBeFalse()
        ->and(array_map(static fn (ScanState $one): array => [$one->status, $one->task], $state->history))->toBe([['running', 'core-integrity'], ['running', 'plugin-integrity'], ['idle', null]])
        ->and($state->history[0]->since)->toBe(strtotime('2026-11-25 12:00:00 UTC'));
});

it('says a run is to be planned when a task stopped on its budget', function (): void {
    $job = new ScanJob(static fn (Closure $progress): Scanner => new Scanner([namedTask('suspicious-files', true)], new InMemoryScanStore, new FakeClock, [], $progress), new ArrayLock, new InMemoryScanStateStore, new FakeClock, static fn (): array => ['suspicious-files']);

    expect($job->run())->toBeTrue()->and($job->unfinished())->toBe(['suspicious-files']);
});

it('steps aside when another run holds the lock, and touches nothing', function (): void {
    $lock = new ArrayLock;
    $lock->held = true;
    $state = new InMemoryScanStateStore;
    $built = 0;

    $ran = (new ScanJob(function () use (&$built): ?Scanner {
        $built++;

        return null;
    }, $lock, $state, new FakeClock, static fn (): array => []))->run();

    expect($ran)->toBeNull()->and($built)->toBe(0)->and($state->history)->toBe([])->and($lock->held)->toBeTrue();
});

it('goes back to idle and lets go of the lock when the scan blows up', function (): void {
    $lock = new ArrayLock;
    $state = new InMemoryScanStateStore;
    $job = new ScanJob(static fn (): never => throw new RuntimeException('boom'), $lock, $state, new FakeClock, static fn (): array => []);

    expect(fn () => $job->run())->toThrow(RuntimeException::class)->and($lock->held)->toBeFalse()->and($state->get()->status)->toBe('idle');
});

it('does nothing, and says idle, when there is no scanner to build', function (): void {
    $state = new InMemoryScanStateStore;

    expect((new ScanJob(static fn (): ?Scanner => null, new ArrayLock, $state, new FakeClock, static fn (): array => []))->run())->toBeFalse()->and($state->get()->status)->toBe('idle');
});

it('says a run is waiting, and for how long a state is believed', function (): void {
    $state = new InMemoryScanStateStore;
    $job = new ScanJob(static fn (): ?Scanner => null, new ArrayLock, $state, new FakeClock('2026-11-25 12:00:00'), static fn (): array => []);

    $job->queued();
    $now = strtotime('2026-11-25 12:00:00 UTC');

    expect($job->state()->status)->toBe('queued')->and($job->state()->isActive($now + 60))->toBeTrue()->and($job->state()->isActive($now + 901))->toBeFalse()
        ->and(ScanState::idle()->isActive($now))->toBeFalse();
});

it('reads a state back, and takes what does not fit for idle', function (): void {
    $state = new ScanState(ScanState::RUNNING, 'core-integrity', 1795600000);

    expect(ScanState::fromArray($state->toArray()))->toEqual($state)
        ->and(ScanState::fromArray(['status' => 'burning', 'since' => 1])->status)->toBe('idle')
        ->and(ScanState::fromArray(['status' => 'running', 'since' => 'x'])->status)->toBe('idle')
        ->and(ScanState::fromArray('text')->status)->toBe('idle')
        ->and(ScanState::fromArray(['status' => 'running', 'task' => 4, 'since' => 1])->task)->toBeNull();
});
