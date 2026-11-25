<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use DateInterval;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\BaselineStore;
use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\PluginChecksums;
use Pollora\Hornet\Domain\Scan\PluginIntegrity;
use Pollora\Hornet\Domain\Scan\PluginInventory;
use Pollora\Hornet\Domain\Scan\PluginVerdict;

/**
 * Are the files of each plugin the ones that were published, or the ones first seen?
 *
 * A plugin that WordPress.org lists is compared with its list (SHA-256 of each file of that version). One it does not know
 * (a premium plugin, one installed from elsewhere) has no reference: its files are hashed the first time and compared
 * afterwards, so a change is seen, a plugin that was bad from the start is not. One request per plugin, slug and version only.
 */
final readonly class PluginIntegrityTask implements ScanTask
{
    public const string ID = 'plugin-integrity';

    public const string THEMES_ID = 'theme-integrity';

    /** How many findings of each kind are listed: the rest is counted. */
    private const int LISTED = 15;

    /** A baseline of more files than this is not kept: the option would be larger than the plugin is worth. */
    private const int BASELINE_FILES = 5000;

    /**
     * @param  int  $budget  seconds after which the plugins left are not looked at (they are named): a scan must end
     */
    public function __construct(
        private PluginInventory $inventory,
        private PluginChecksums $official,
        private PluginIntegrity $integrity,
        private BaselineStore $baselines,
        private Clock $clock,
        private int $budget = 240,
        private string $id = self::ID,
        private string $kind = 'plugin',
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function run(?Pins $pinned, bool $repin): TaskResult
    {
        $plugins = $this->inventory->plugins();

        if ($plugins === []) {
            return new TaskResult(Finding::pass($this->id, sprintf('No %s has a folder of its own.', $this->kind)));
        }

        $deadline = $this->clock->now()->add(new DateInterval('PT'.$this->budget.'S'));
        $stored = $this->baselines->load();
        // Pinning again accepts the plugins as they are now: what was first seen is looked at again
        $baselines = $repin ? [] : $stored;
        $verdicts = [];
        $byList = 0;
        $byBaseline = 0;
        $recorded = [];
        $unchecked = [];

        foreach ($plugins as $plugin) {
            if ($this->clock->now() > $deadline) {
                $unchecked[] = $plugin->slug.' (out of time)';

                continue;
            }

            $look = $this->look($plugin, $baselines);

            if ($look->first instanceof Baseline) {
                $baselines[$plugin->slug] = $look->first;
                $recorded[] = $plugin->slug;
            } elseif ($look->verdict instanceof PluginVerdict) {
                $verdicts[] = $look->verdict;
                $look->fromBaseline ? $byBaseline++ : $byList++;
            } else {
                $unchecked[] = $plugin->slug.' ('.$look->why.')';
            }
        }

        // What was seen of a plugin that is gone is forgotten
        $baselines = array_intersect_key($baselines, array_flip(array_map(static fn (InstalledPlugin $plugin): string => $plugin->slug, $plugins)));

        if ($baselines != $stored) {
            $this->baselines->save($baselines);
        }

        return new TaskResult($this->finding($verdicts, $byList, $byBaseline, $recorded, $unchecked));
    }

    /**
     * @param  array<string, Baseline>  $baselines
     */
    private function look(InstalledPlugin $plugin, array $baselines): PluginLook
    {
        try {
            $list = $this->official->checksums($plugin->slug, $plugin->version);
        } catch (TransportException) {
            return PluginLook::failed('WordPress.org did not answer');
        }

        if ($list !== null) {
            $verdict = $this->integrity->inspect($plugin->slug, $list);

            return $verdict instanceof PluginVerdict ? PluginLook::listed($verdict) : PluginLook::failed('folder cannot be read');
        }

        $known = $baselines[$plugin->slug] ?? null;

        if ($known instanceof Baseline && $known->version === $plugin->version) {
            $verdict = $this->integrity->inspect($plugin->slug, array_map(static fn (string $hash): array => [$hash], $known->hashes));

            return $verdict instanceof PluginVerdict ? PluginLook::againstFirstLook($verdict) : PluginLook::failed('folder cannot be read');
        }

        $hashes = $this->integrity->hashes($plugin->slug);

        return match (true) {
            $hashes === null => PluginLook::failed('folder cannot be read'),
            count($hashes) > self::BASELINE_FILES => PluginLook::failed('too many files to keep a first look'),
            default => PluginLook::firstLook(new Baseline($plugin->version, $hashes)),
        };
    }

    /**
     * @param  list<PluginVerdict>  $verdicts
     * @param  list<string>  $recorded
     * @param  list<string>  $unchecked
     */
    private function finding(array $verdicts, int $byList, int $byBaseline, array $recorded, array $unchecked): Finding
    {
        $problems = array_values(array_filter($verdicts, static fn (PluginVerdict $verdict): bool => ! $verdict->isClean()));
        $notes = [
            ...($recorded === [] ? [] : [sprintf('First look kept for %d %s(s) WordPress.org does not know, nothing to compare with yet: %s', count($recorded), $this->kind, implode(', ', $recorded))]),
            ...($unchecked === [] ? [] : [sprintf('Not checked: %s', implode(', ', $unchecked))]),
        ];

        if ($problems !== []) {
            $details = [
                ...$this->listed('Changed', $problems, static fn (PluginVerdict $verdict): array => $verdict->modified),
                ...$this->listed('Script that does not belong', $problems, static fn (PluginVerdict $verdict): array => $verdict->unexpectedCode),
                ...$this->listed('Missing', $problems, static fn (PluginVerdict $verdict): array => $verdict->missing),
                ...$notes,
            ];
            $hard = array_filter($problems, static fn (PluginVerdict $verdict): bool => $verdict->modified !== [] || $verdict->unexpectedCode !== []);

            return Finding::fail(
                $this->id,
                $hard === [] ? Severity::Medium : Severity::High,
                sprintf('%d %s changed since it was published or first seen: %s.', count($problems), count($problems) === 1 ? $this->kind.' has' : $this->kind.'s have', implode(', ', array_map(static fn (PluginVerdict $verdict): string => $verdict->slug, array_slice($problems, 0, 5)))),
                $details,
                'Look at each file. If you did not change it, reinstall the '.$this->kind.' from its source after a backup, and find how it was changed. Hornet never changes a file.',
            );
        }

        if ($byList + $byBaseline === 0) {
            return Finding::unknown($this->id, $notes === [] ? sprintf('No %s could be compared.', $this->kind) : implode(' ', $notes));
        }

        // A theme has no list on WordPress.org: only what was first seen can be matched
        $matched = $this->kind === 'plugin' ? sprintf('%d plugin(s) match WordPress.org, %d match what was first seen', $byList, $byBaseline) : sprintf('%d %s(s) match what was first seen', $byBaseline, $this->kind);

        return Finding::pass($this->id, sprintf('%s%s.', $matched, $recorded === [] ? '' : sprintf(', %d first seen now', count($recorded))), $notes);
    }

    /**
     * @param  list<PluginVerdict>  $problems
     * @param  callable(PluginVerdict): list<string>  $of
     * @return list<string>
     */
    private function listed(string $label, array $problems, callable $of): array
    {
        $lines = [];

        foreach ($problems as $verdict) {
            foreach ($of($verdict) as $path) {
                $lines[] = sprintf('%s: %s/%s', $label, $verdict->slug, $path);
            }
        }

        $shown = array_slice($lines, 0, self::LISTED);

        return count($lines) > self::LISTED ? [...$shown, sprintf('%s: and %d more', $label, count($lines) - self::LISTED)] : $shown;
    }
}
