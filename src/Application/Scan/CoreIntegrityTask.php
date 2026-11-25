<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\CoreChecksums;
use Pollora\Hornet\Domain\Scan\CoreIntegrity;
use Pollora\Hornet\Domain\Scan\CoreVerdict;
use Pollora\Hornet\Domain\Scan\FileSystem;
use Pollora\Hornet\Domain\Scan\Pins;

/**
 * Are the WordPress core files the ones WordPress.org published for this release?
 *
 * A file that differs, a file that is gone, a file that does not belong in wp-admin or wp-includes. A project that patches the
 * core on purpose names those files (`allowed`): they may differ, and their hash is pinned so that a second change is still seen.
 */
final readonly class CoreIntegrityTask implements ScanTask
{
    public const string ID = 'core-integrity';

    /** How many files a finding lists per kind: the rest is counted. */
    private const int LISTED = 15;

    /**
     * @param  list<string>  $allowed  core files that are meant to differ
     */
    public function __construct(
        private CoreChecksums $official,
        private FileSystem $files,
        private string $version,
        private string $locale,
        private array $allowed = [],
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(?Pins $pinned, bool $repin): TaskResult
    {
        $scope = $this->version.' ('.$this->locale.')';

        try {
            $list = $this->official->checksums($this->version, $this->locale);
        } catch (TransportException $transportException) {
            return $this->unknown(sprintf('WordPress.org did not answer, so the core files were not compared: %s', $transportException->getMessage()), $pinned);
        }

        if ($list === null) {
            return $this->unknown(sprintf('WordPress.org publishes no checksums for WordPress %s: a beta, a development build or an unreleased version cannot be verified.', $scope), $pinned);
        }

        // A pin belongs to one release: an update, or another locale, starts over
        $current = $pinned instanceof Pins && $pinned->scope === $scope && ! $repin ? $pinned->hashes : [];
        $verdict = (new CoreIntegrity($this->files))->inspect($list, $this->allowed, $current);

        if (! $verdict instanceof CoreVerdict) {
            return $this->unknown('The core files cannot be listed from here (open_basedir or permissions).', $pinned);
        }

        // A file seen for the first time is pinned as it is. One that changed keeps its old pin, so that it keeps being reported.
        $kept = $verdict->patched;

        foreach ($verdict->changedSincePinned as $path) {
            $kept[$path] = $current[$path];
        }

        $pins = new Pins($scope, $kept);

        return new TaskResult($this->finding($verdict, $scope), $pins);
    }

    private function finding(CoreVerdict $verdict, string $scope): Finding
    {
        $details = [
            ...$this->listed('Changed', $verdict->modified),
            ...$this->listed('Changed since first seen', $verdict->changedSincePinned),
            ...$this->listed('Missing', $verdict->missing),
            ...$this->listed('Not part of WordPress', $verdict->unexpected),
            ...$this->listed('Could not be read', $verdict->unreadable),
        ];

        if ($verdict->isClean()) {
            $allowed = array_keys($verdict->patched);

            return Finding::pass(self::ID, sprintf('The %d core files match WordPress %s on WordPress.org.', $verdict->checked, $scope), [...$details, ...($allowed === [] ? [] : [sprintf('Allowed to differ, and unchanged since first seen: %s', implode(', ', $allowed))])]);
        }

        $severity = match (true) {
            $verdict->modified !== [] || $verdict->changedSincePinned !== [] || $verdict->unexpectedCode() !== [] => Severity::High,
            $verdict->missing !== [] => Severity::Medium,
            default => Severity::Low,
        };

        $found = count($verdict->modified) + count($verdict->changedSincePinned) + count($verdict->missing) + count($verdict->unexpected);

        return Finding::fail(
            self::ID,
            $severity,
            sprintf('%d core %s differ from WordPress %s on WordPress.org.', $found, $found === 1 ? 'file' : 'files', $scope),
            $details,
            'Look at each file. If you did not change it, restore the core after a backup (`wp core download --force --skip-content`) and find how it was changed. If the change is yours, list it in scanner.allow_modified. Hornet never changes a file.',
        );
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function listed(string $label, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $lines = array_map(static fn (string $path): string => $label.': '.$path, array_slice($paths, 0, self::LISTED));

        if (count($paths) > self::LISTED) {
            $lines[] = sprintf('%s: and %d more', $label, count($paths) - self::LISTED);
        }

        return $lines;
    }

    private function unknown(string $reason, ?Pins $pinned): TaskResult
    {
        // Nothing was learned: keep the pins as they were
        return new TaskResult(Finding::unknown(self::ID, $reason), $pinned);
    }
}
