<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanStore;
use Throwable;

/**
 * Runs the scan tasks, keeps what they found, and nothing else: it never deletes, repairs or quarantines a file.
 *
 * It is not a check. A check reads and never asks the network; a scan reads the disk, may ask WordPress.org, and takes time.
 * It runs when someone asks (`wp hornet scan`), never inside a page request.
 */
final readonly class Scanner
{
    /**
     * @param  list<ScanTask>  $tasks
     */
    public function __construct(
        private array $tasks,
        private ScanStore $store,
        private Clock $clock,
    ) {}

    /**
     * @return list<string> the ids of the tasks, in the order they run
     */
    public function tasks(): array
    {
        return array_map(static fn (ScanTask $task): string => $task->id(), $this->tasks);
    }

    /**
     * @param  bool  $repin  accept the files that are allowed to differ as they are now
     */
    public function run(bool $repin = false): ScanSnapshot
    {
        $previous = $this->store->last();
        $findings = [];
        $pins = [];

        foreach ($this->tasks as $task) {
            $id = $task->id();
            $result = $this->runOne($task, $previous?->pins($id), $repin);
            $findings[$id] = $result->finding;

            if ($result->pins instanceof Pins) {
                $pins[$id] = $result->pins;
            }
        }

        $snapshot = new ScanSnapshot($this->clock->now(), $findings, $pins);
        $this->store->save($snapshot);

        return $snapshot;
    }

    /**
     * A task that fails is reported, never skipped: a scan that hides a crash would look cleaner than the site is.
     */
    private function runOne(ScanTask $task, ?Pins $pinned, bool $repin): TaskResult
    {
        try {
            return $task->run($pinned, $repin);
        } catch (Throwable $throwable) {
            return new TaskResult(Finding::unknown($task->id(), sprintf('The scan could not run: %s', $throwable::class)), $pinned);
        }
    }
}
