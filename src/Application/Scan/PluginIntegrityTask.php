<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use DateInterval;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\I18n\Message;
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
 *
 * @internal
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
            return new TaskResult(Finding::pass($this->id, $this->isPlugin() ? Message::of('No plugin has a folder of its own.') : Message::of('No theme has a folder of its own.')));
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
                $unchecked[] = Message::of('%s (out of time)', $plugin->slug);

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
                $unchecked[] = Message::of('%s (%s)', $plugin->slug, Message::known((string) $look->why));
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
            return PluginLook::failed(Message::mark('WordPress.org did not answer'));
        }

        if ($list !== null) {
            $verdict = $this->integrity->inspect($plugin->slug, $list);

            return $verdict instanceof PluginVerdict ? PluginLook::listed($verdict) : PluginLook::failed(Message::mark('folder cannot be read'));
        }

        $known = $baselines[$plugin->slug] ?? null;

        if ($known instanceof Baseline && $known->version === $plugin->version) {
            $verdict = $this->integrity->inspect($plugin->slug, array_map(static fn (string $hash): array => [$hash], $known->hashes));

            return $verdict instanceof PluginVerdict ? PluginLook::againstFirstLook($verdict) : PluginLook::failed(Message::mark('folder cannot be read'));
        }

        $hashes = $this->integrity->hashes($plugin->slug);

        return match (true) {
            $hashes === null => PluginLook::failed(Message::mark('folder cannot be read')),
            count($hashes) > self::BASELINE_FILES => PluginLook::failed(Message::mark('too many files to keep a first look')),
            default => PluginLook::firstLook(new Baseline($plugin->version, $hashes)),
        };
    }

    /**
     * @param  list<PluginVerdict>  $verdicts
     * @param  list<string>  $recorded
     * @param  list<Message>  $unchecked
     */
    private function finding(array $verdicts, int $byList, int $byBaseline, array $recorded, array $unchecked): Finding
    {
        $problems = array_values(array_filter($verdicts, static fn (PluginVerdict $verdict): bool => ! $verdict->isClean()));
        $notes = $this->notes($recorded, $unchecked);
        $lines = array_map(static fn (array $parts): Message => Message::join('', $parts), $notes);

        if ($problems !== []) {
            $details = [
                ...$this->listed(Message::mark('Changed'), $problems, static fn (PluginVerdict $verdict): array => $verdict->modified),
                ...$this->listed(Message::mark('Script that does not belong'), $problems, static fn (PluginVerdict $verdict): array => $verdict->unexpectedCode),
                ...$this->listed(Message::mark('Missing'), $problems, static fn (PluginVerdict $verdict): array => $verdict->missing),
                ...$lines,
            ];
            $hard = array_filter($problems, static fn (PluginVerdict $verdict): bool => $verdict->modified !== [] || $verdict->unexpectedCode !== []);
            $count = count($problems);
            $slugs = implode(', ', array_map(static fn (PluginVerdict $verdict): string => $verdict->slug, array_slice($problems, 0, 5)));

            return Finding::fail(
                $this->id,
                $hard === [] ? Severity::Medium : Severity::High,
                $this->isPlugin()
                    ? Message::plural('%d plugin has changed since it was published or first seen: %s.', '%d plugins have changed since it was published or first seen: %s.', $count, $count, $slugs)
                    : Message::plural('%d theme has changed since it was published or first seen: %s.', '%d themes have changed since it was published or first seen: %s.', $count, $count, $slugs),
                $details,
                $this->isPlugin()
                    ? Message::of('Look at each file. If you did not change it, reinstall the plugin from its source after a backup, and find how it was changed. Hornet never changes a file.')
                    : Message::of('Look at each file. If you did not change it, reinstall the theme from its source after a backup, and find how it was changed. Hornet never changes a file.'),
            );
        }

        if ($byList + $byBaseline === 0) {
            if ($notes === []) {
                return Finding::unknown($this->id, $this->isPlugin() ? Message::of('No plugin could be compared.') : Message::of('No theme could be compared.'));
            }

            // One flat list of parts: a stored message keeps only a few levels, and a scan that is kept adds one around this
            $parts = [];

            foreach ($notes as $note) {
                $parts = [...$parts, ...($parts === [] ? [] : [Message::raw(' ')]), ...$note];
            }

            return Finding::unknown($this->id, Message::join('', $parts));
        }

        // A theme has no list on WordPress.org: only what was first seen can be matched
        $matched = $this->isPlugin()
            ? Message::of('%d plugin(s) match WordPress.org, %d match what was first seen', $byList, $byBaseline)
            : Message::of('%d theme(s) match what was first seen', $byBaseline);
        $new = $recorded === [] ? [] : [Message::of(', %d first seen now', count($recorded))];

        return Finding::pass($this->id, Message::join('', [$matched, ...$new, Message::raw('.')]), $lines);
    }

    /**
     * What is to be said beside the verdict, each note as the parts it is made of.
     *
     * @param  list<string>  $recorded
     * @param  list<Message>  $unchecked
     * @return list<list<Message>>
     */
    private function notes(array $recorded, array $unchecked): array
    {
        $notes = [];

        if ($recorded !== []) {
            $notes[] = [$this->isPlugin()
                ? Message::of('First look kept for %d plugin(s) WordPress.org does not know, nothing to compare with yet: %s', count($recorded), implode(', ', $recorded))
                : Message::of('First look kept for %d theme(s) WordPress.org does not know, nothing to compare with yet: %s', count($recorded), implode(', ', $recorded))];
        }

        if ($unchecked !== []) {
            $parts = [Message::of('Not checked:')];

            foreach ($unchecked as $index => $one) {
                $parts[] = Message::raw($index === 0 ? ' ' : ', ');
                $parts[] = $one;
            }

            $notes[] = $parts;
        }

        return $notes;
    }

    private function isPlugin(): bool
    {
        return $this->kind === 'plugin';
    }

    /**
     * @param  list<PluginVerdict>  $problems
     * @param  callable(PluginVerdict): list<string>  $of
     * @return list<Message>
     */
    private function listed(string $label, array $problems, callable $of): array
    {
        $lines = [];

        foreach ($problems as $verdict) {
            foreach ($of($verdict) as $path) {
                $lines[] = Message::of('%s: %s/%s', Message::known($label), $verdict->slug, $path);
            }
        }

        $shown = array_slice($lines, 0, self::LISTED);

        return count($lines) > self::LISTED ? [...$shown, Message::of('%s: and %d more', Message::known($label), count($lines) - self::LISTED)] : $shown;
    }
}
