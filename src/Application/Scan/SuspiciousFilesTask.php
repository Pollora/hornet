<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use DateInterval;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Scan\FileInspector;
use Pollora\Hornet\Domain\Scan\FileSystem;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\Suspicion;

/**
 * Walks the content folder (uploads, themes, mu-plugins, drop-ins) for files that would not be left unsaid.
 *
 * The plugins are left to the plugin scan, which knows what they should hold. A scan never lasts for ever: with a time budget
 * (the cron has one), it stops where it is, keeps the cursor and what it found, and the next run goes on from there.
 * From the command line there is no budget, and the walk is made in one go. It never deletes, quarantines or repairs a file.
 */
final readonly class SuspiciousFilesTask implements ScanTask
{
    public const string ID = 'suspicious-files';

    /** The scope of the pins of a walk that is not finished: what follows is the last file looked at. */
    private const string CURSOR = 'cursor:';

    /** What is kept of a walk in progress and what a finding lists: more than this is counted. */
    private const int KEPT = 200;

    private const int LISTED = 20;

    /** The key, among the pins of a walk, that counts what could not be kept. */
    private const string DROPPED = '~dropped';

    /** Folders that are not the site's code: tools, caches that hold generated PHP on purpose, languages. */
    private const array SKIPPED_ANYWHERE = ['node_modules', '.git', 'cache', 'wflogs'];

    /** Folders of the content folder that are left to others (the plugin scan) or hold no code. */
    private const array SKIPPED_AT_ROOT = ['plugins', 'languages', 'upgrade'];

    /**
     * @param  list<string>  $ignored  paths, relative to the content folder, that are left alone: a file or a folder (the owner's false positives)
     * @param  int|null  $budget  seconds before the walk stops and goes on next time; null for no limit
     */
    public function __construct(
        private FileSystem $content,
        private FileInspector $inspector,
        private Clock $clock,
        private array $ignored = [],
        private ?int $budget = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(?Pins $pinned, bool $repin): TaskResult
    {
        $files = $this->content->files('', true);

        if ($files === null) {
            return new TaskResult(Finding::unknown(self::ID, 'The content folder cannot be listed from here (open_basedir or permissions).'), $pinned);
        }

        $after = $pinned instanceof Pins && str_starts_with($pinned->scope, self::CURSOR) && ! $repin ? substr($pinned->scope, strlen(self::CURSOR)) : null;
        [$found, $dropped] = $after === null ? [[], 0] : $this->kept($pinned);
        $deadline = $this->budget === null ? null : $this->clock->now()->add(new DateInterval('PT'.$this->budget.'S'));
        $looked = 0;
        $last = null;

        foreach ($files as $path) {
            if ($after !== null && strcmp($path, $after) <= 0) {
                continue;
            }

            if ($this->skipped($path)) {
                continue;
            }

            if ($deadline instanceof \DateTimeImmutable && $looked % 50 === 0 && $this->clock->now() > $deadline && $looked > 0) {
                return $this->partial($found, $dropped, (string) $last, $looked);
            }

            $suspicion = $this->inspector->inspect($path);
            if ($suspicion instanceof Suspicion) {
                $found[$path] = $suspicion;
            }

            $looked++;
            $last = $path;
        }

        // Done: the cursor is dropped, and the next run starts again from the first file
        return new TaskResult($this->finding($found, $looked, $dropped));
    }

    /**
     * @param  array<string, Suspicion>  $found
     */
    private function partial(array $found, int $dropped, string $last, int $looked): TaskResult
    {
        $hashes = [];

        foreach (array_slice($found, 0, self::KEPT, true) as $path => $suspicion) {
            $hashes[$path] = (string) json_encode($suspicion->toArray());
        }

        // More than can be kept between two runs: they are counted, and said
        $lost = $dropped + max(0, count($found) - self::KEPT);

        if ($lost > 0) {
            $hashes[self::DROPPED] = (string) $lost;
        }

        return new TaskResult(
            Finding::unknown(self::ID, sprintf('The walk of the content folder is not finished: %d files looked at in this run, it goes on from %s next time.', $looked, $last)),
            new Pins(self::CURSOR.$last, $hashes),
            unfinished: true,
        );
    }

    /**
     * @return array{array<string, Suspicion>, int} what an earlier run found, and how many it found beyond what it could keep
     */
    private function kept(?Pins $pinned): array
    {
        $found = [];
        $dropped = 0;

        foreach ($pinned->hashes ?? [] as $path => $json) {
            if ($path === self::DROPPED) {
                $dropped = max(0, (int) $json);

                continue;
            }

            $suspicion = Suspicion::fromArray(json_decode($json, true));

            if ($suspicion instanceof Suspicion) {
                $found[(string) $path] = $suspicion;
            }
        }

        return [$found, $dropped];
    }

    /**
     * @param  array<string, Suspicion>  $found
     */
    private function finding(array $found, int $looked, int $dropped = 0): Finding
    {
        if ($found === [] && $dropped === 0) {
            return Finding::pass(self::ID, sprintf('Nothing suspicious in the %d files of the content folder that were looked at.', $looked));
        }

        $list = array_values($found);
        usort($list, static fn (Suspicion $a, Suspicion $b): int => $b->severity->rank() <=> $a->severity->rank() ?: strcmp($a->path, $b->path));
        $details = array_map(static fn (Suspicion $suspicion): string => sprintf('[%s] %s', $suspicion->severity->value, $suspicion->line()), array_slice($list, 0, self::LISTED));

        if (count($list) > self::LISTED) {
            $details[] = sprintf('and %d more', count($list) - self::LISTED);
        }

        if ($dropped > 0) {
            $details[] = sprintf('and %d more were found in an earlier run and not kept: run `wp hornet scan` from the command line to list them all', $dropped);
        }

        return Finding::fail(
            self::ID,
            $list === [] ? Severity::Medium : $list[0]->severity,
            sprintf('%d %s in the content folder would not be left unsaid.', count($list) + $dropped, count($list) + $dropped === 1 ? 'file' : 'files'),
            $details,
            'Look at each file before anything else, and do not open it in a browser. A file you know and trust can be left alone with scanner.ignore_paths. If you do not know it, find how it came there (an old plugin, a form that accepts uploads) before you remove it. Hornet never changes a file.',
        );
    }

    private function skipped(string $path): bool
    {
        $parts = explode('/', $path);

        if (in_array($parts[0], self::SKIPPED_AT_ROOT, true) || array_intersect($parts, self::SKIPPED_ANYWHERE) !== []) {
            return true;
        }

        foreach ($this->ignored as $ignored) {
            if ($path === $ignored || str_starts_with($path, rtrim($ignored, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
