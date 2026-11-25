<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Closure;
use Pollora\Hornet\Application\Notification\RunLock;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Domain\Scan\ScanStateStore;

/**
 * One run of the scan as a job that nobody is watching (the cron, the "Scan now" button): under a lock, with its progress told
 * as it goes, so that the administration screen can show which task it is at. The lock is let go whatever happens.
 */
final readonly class ScanJob
{
    /**
     * @param  Closure(Closure(string): void): ?Scanner  $scanner  built when the job runs, and given what to call before each task
     * @param  Closure(): list<string>  $unfinished  the tasks that stopped on their budget at the last run
     */
    public function __construct(
        private Closure $scanner,
        private RunLock $lock,
        private ScanStateStore $state,
        private Clock $clock,
        private Closure $unfinished,
    ) {}

    /**
     * @param  list<string>|null  $only  the tasks to run; all of them when null
     * @return bool|null whether a task stopped on its budget, and a run that goes on is to be planned; null when it did not run (another run holds the lock)
     */
    public function run(?array $only = null): ?bool
    {
        if (! $this->lock->acquire()) {
            return null;
        }

        try {
            $scanner = ($this->scanner)(fn (string $task) => $this->state->save(new ScanState(ScanState::RUNNING, $task, $this->clock->now()->getTimestamp())));

            return $scanner instanceof Scanner && $scanner->run(false, $only)->unfinished !== [];
        } finally {
            $this->state->save(ScanState::idle());
            $this->lock->release();
        }
    }

    /**
     * @return list<string>
     */
    public function unfinished(): array
    {
        return ($this->unfinished)();
    }

    public function state(): ScanState
    {
        return $this->state->get();
    }

    /**
     * Says that a run is waiting for the cron to pick it up.
     */
    public function queued(): void
    {
        $this->state->save(new ScanState(ScanState::QUEUED, null, $this->clock->now()->getTimestamp()));
    }
}
