<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Closure;
use DateTimeImmutable;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanStore;
use Throwable;

/**
 * Runs the scan tasks, keeps what they found, and nothing else: it never deletes, repairs or quarantines a file.
 *
 * It is not a check. A check reads and never asks the network; a scan reads the disk, may ask WordPress.org, and takes time.
 * It runs when someone asks (`wp hornet scan`), never inside a page request.
 *
 * @internal
 */
final readonly class Scanner
{
    /** What a failure that could not be checked again says in its details. */
    private const string KEPT = 'Not verified again on';

    /**
     * @param  list<ScanTask>  $tasks
     * @param  Closure(string): void|null  $progress  called with the id of each task before it runs
     * @param  array<string, string>  $ignored  id of a task => why its finding is set aside (the `ignore` setting)
     * @param  int|null  $timeBox  seconds after which no further task is started: it is left for a run that goes on; null for no limit
     */
    public function __construct(
        private array $tasks,
        private ScanStore $store,
        private Clock $clock,
        private array $ignored = [],
        private ?Closure $progress = null,
        private ?int $timeBox = null,
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
     * @param  list<string>|null  $only  the tasks to run; the others keep what the last scan found (a continuation does not start over, nor asks WordPress.org again)
     */
    public function run(bool $repin = false, ?array $only = null): ScanSnapshot
    {
        $previous = $this->store->last();
        $now = $this->clock->now();
        $ids = $this->tasks();
        $findings = [];
        $pins = [];
        $unfinished = [];

        $deadline = $this->timeBox === null ? null : $now->getTimestamp() + $this->timeBox;
        $started = 0;
        $left = false;

        // What an earlier scan found stays until the task that found it has run again
        foreach ($ids as $id) {
            $finding = $previous?->finding($id);
            if ($finding instanceof Finding) {
                $findings[$id] = $finding;
            }

            $kept = $previous?->pins($id);
            if ($kept instanceof Pins) {
                $pins[$id] = $kept;
            }
        }

        foreach ($this->tasks as $task) {
            $id = $task->id();

            if ($only !== null && ! in_array($id, $only, true) && isset($findings[$id])) {
                // Not run again: it is still unfinished if it was
                if (in_array($id, $previous->unfinished ?? [], true)) {
                    $unfinished[] = $id;
                }

                continue;
            }

            // A request has a limit, and one task after another would pass it. One task at least runs, or nothing would move
            if ($deadline !== null && $started > 0 && $this->clock->now()->getTimestamp() >= $deadline) {
                $unfinished[] = $id;
                $left = true;

                continue;
            }

            $started++;

            // Told before the task, so that someone who waits knows which one it is at
            if ($this->progress instanceof Closure) {
                ($this->progress)($id);
            }

            $result = $this->runOne($task, $previous?->pins($id), $repin);
            $findings[$id] = $this->kept($result->finding, $previous?->finding($id), $now);
            unset($pins[$id]);
            if ($result->pins instanceof Pins) {
                $pins[$id] = $result->pins;
            }

            if ($result->unfinished) {
                $unfinished[] = $id;
            }

            // Saved after each task: a run that dies on the time limit loses the task it was in, not those it had done.
            // Until the last one is done the scan keeps its old date, so that what was not run again does not look fresh.
            $this->store->save(new ScanSnapshot($previous instanceof ScanSnapshot ? $previous->at : $now, $findings, $pins, $unfinished));
        }

        // Tasks that were not reached keep the scan at its old date: what was not looked at again does not look fresh
        $snapshot = new ScanSnapshot($left && $previous instanceof ScanSnapshot ? $previous->at : $now, $findings, $pins, $unfinished);
        $this->store->save($snapshot);

        return $snapshot;
    }

    /**
     * The findings of a scan, with the ones the owner chose to live with set aside, on the reason they wrote.
     */
    public function report(ScanSnapshot $snapshot): Report
    {
        return new Report(array_values(array_map(fn (Finding $finding): Finding => isset($this->ignored[$finding->checkId]) ? $finding->ignoredFor($this->ignored[$finding->checkId]) : $finding, $snapshot->findings)));
    }

    /**
     * A scan that could not look must not erase what an earlier one found: a request to WordPress.org that times out
     * (or is made to) would otherwise turn a high finding into "not verified". The failure stays, with the reason it was not checked again.
     */
    private function kept(Finding $found, ?Finding $before, DateTimeImmutable $now): Finding
    {
        if ($found->outcome !== Outcome::Unknown || ! $before instanceof Finding || $before->outcome !== Outcome::Fail) {
            return $found;
        }

        // A failure that was already kept once is not stacked: only the latest reason is told
        $details = array_values(array_filter($before->detailMessages(), static fn (Message $line): bool => ! str_starts_with($line->english(), self::KEPT)));

        // The template starts with the words of KEPT: it is written out in full, as the translations are read from it
        $line = Message::of('Not verified again on %s UTC: %s', $now->format('Y-m-d H:i'), $found->summaryMessage());

        return Finding::fail($before->checkId, $before->severity, $before->summaryMessage(), [...$details, $line], $before->fixMessage());
    }

    /**
     * A task that fails is reported, never skipped: a scan that hides a crash would look cleaner than the site is.
     */
    private function runOne(ScanTask $task, ?Pins $pinned, bool $repin): TaskResult
    {
        try {
            return $task->run($pinned, $repin);
        } catch (Throwable $throwable) {
            return new TaskResult(Finding::unknown($task->id(), Message::of('The scan could not run: %s', $throwable::class)), $pinned);
        }
    }
}
