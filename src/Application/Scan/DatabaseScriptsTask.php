<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Closure;
use DateInterval;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Exceptions\ContentReadException;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Scan\ContentEntry;
use Pollora\Hornet\Domain\Scan\ContentKind;
use Pollora\Hornet\Domain\Scan\ContentRules;
use Pollora\Hornet\Domain\Scan\ContentStore;
use Pollora\Hornet\Domain\Scan\Pins;

/**
 * Looks for disguised JavaScript in the database: what an SQL injection or a stolen account leaves in posts, options, comments
 * and profiles.
 *
 * Built for large databases. The tables are read in ranges of identifiers, whose width follows how long a range takes; the
 * database does the filtering, and only the few entries that hold a mark are read. With a time budget it stops between two
 * ranges, keeps its place and what it found, and goes on next time. It never changes anything.
 *
 * @internal
 */
final readonly class DatabaseScriptsTask implements ScanTask
{
    public const string ID = 'database-scripts';

    private const string CURSOR = 'cursor:';

    private const string KEPT_HOSTS = '~hosts';

    private const string KEPT_COUNTS = '~counts';

    private const int KEPT = 200;

    private const int LISTED = 20;

    /** Identifiers in a range: where it starts, and its limits. */
    private const int RANGE = 500;

    private const int NARROWEST = 50;

    private const int WIDEST = 5000;

    /** Entries whose texts are in memory at once. */
    private const int BATCH = 20;

    private const int HOSTS = 10;

    /**
     * @param  list<string>  $ignored  entries left alone: `post:12`, `comment:3`, `user:5`, `option:widget_text`
     * @param  int|null  $budget  seconds before it stops and goes on next time; null for no limit
     * @param  Closure(): float|null  $time  a clock in seconds, to judge how long a range took; a test sets it
     */
    public function __construct(
        private ContentStore $store,
        private Clock $clock,
        private array $ignored = [],
        private ?int $budget = null,
        private ?Closure $time = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(?Pins $pinned, bool $repin): TaskResult
    {
        $sources = $this->store->sources();
        [$source, $after, $found, $hosts, $looked, $dropped] = $this->resume($repin ? null : $pinned, $sources);
        $deadline = $this->budget === null ? null : $this->clock->now()->add(new DateInterval('PT'.$this->budget.'S'));
        $time = $this->time ?? static fn (): float => microtime(true);
        $width = self::RANGE;
        $ranges = 0;

        try {
            for ($index = (int) array_search($source, $sources, true); $index < count($sources); $index++, $after = 0) {
                $source = $sources[$index];

                while (($from = $this->store->next($source, $after)) !== null) {
                    // At least one range in a run, or a slow database would never move
                    if ($deadline instanceof \DateTimeImmutable && $ranges > 0 && $this->clock->now() > $deadline) {
                        return $this->partial($source, $after, $found, $hosts, $looked, $dropped);
                    }

                    $started = $time();
                    $to = $from + $width - 1;
                    $ids = $this->store->matches($source, $from, $to);
                    $took = $time() - $started;

                    foreach (array_chunk($ids, self::BATCH) as $batch) {
                        foreach ($this->store->entries($source, $batch) as $entry) {
                            if ($this->ignored($entry)) {
                                continue;
                            }

                            $looked++;
                            $verdict = ContentRules::look($entry->text, $entry->kind);
                            $hosts = array_slice(array_values(array_unique([...$hosts, ...$verdict['hosts']])), 0, self::HOSTS);

                            if (! $verdict['severity'] instanceof Severity) {
                                continue;
                            }

                            if (count($found) < self::KEPT) {
                                $found[$entry->kind->value.':'.$entry->id] = ['severity' => $verdict['severity']->value, 'name' => self::safeName($entry->name), 'reasons' => $verdict['reasons']];
                            } else {
                                $dropped++;
                            }
                        }
                    }

                    $after = $to;
                    $ranges++;
                    $width = match (true) {
                        $took < 0.05 => min(self::WIDEST, $width * 2),
                        $took > 0.25 => max(self::NARROWEST, intdiv($width, 2)),
                        default => $width,
                    };
                }
            }
        } catch (ContentReadException) {
            // What was found and the place reached are kept: the next scan tries again from there
            return new TaskResult($this->unreadable($found), $this->pins($source, $after, $found, $hosts, $looked, $dropped));
        }

        return new TaskResult($this->finding($found, $hosts, $looked, $dropped, false));
    }

    /**
     * @param  list<string>  $sources
     * @return array{string, int, array<string, array{severity: string, name: string, reasons: list<string>}>, list<string>, int, int}
     */
    private function resume(?Pins $pinned, array $sources): array
    {
        $fresh = [$sources[0] ?? '', 0, [], [], 0, 0];

        if (! $pinned instanceof Pins || preg_match('/^'.self::CURSOR.'([a-z]+):(\d+)$/D', $pinned->scope, $at) !== 1 || ! in_array($at[1], $sources, true)) {
            return $fresh;
        }

        $found = [];
        $hosts = [];
        $looked = 0;
        $dropped = 0;

        foreach ($pinned->hashes as $key => $json) {
            $value = json_decode($json, true);

            if (! is_array($value)) {
                continue;
            }

            if ($key === self::KEPT_HOSTS) {
                $hosts = array_values(array_filter($value, is_string(...)));
            } elseif ($key === self::KEPT_COUNTS) {
                $looked = is_int($value[0] ?? null) ? $value[0] : 0;
                $dropped = is_int($value[1] ?? null) ? $value[1] : 0;
            } elseif (is_string($value['severity'] ?? null) && Severity::tryFrom($value['severity']) instanceof Severity && is_array($value['reasons'] ?? null)) {
                $found[(string) $key] = ['severity' => $value['severity'], 'name' => is_string($value['name'] ?? null) ? $value['name'] : '', 'reasons' => array_values(array_filter($value['reasons'], is_string(...)))];
            }
        }

        return [$at[1], (int) $at[2], $found, $hosts, $looked, $dropped];
    }

    /**
     * @param  array<string, array{severity: string, name: string, reasons: list<string>}>  $found
     * @param  list<string>  $hosts
     */
    private function partial(string $source, int $after, array $found, array $hosts, int $looked, int $dropped): TaskResult
    {
        return new TaskResult($this->finding($found, $hosts, $looked, $dropped, true), $this->pins($source, $after, $found, $hosts, $looked, $dropped), unfinished: true);
    }

    /**
     * @param  array<string, array{severity: string, name: string, reasons: list<string>}>  $found
     */
    private function unreadable(array $found): Finding
    {
        $note = Message::of('The database could not be read: nothing can be said of the part that was not.');

        return $found === [] ? Finding::unknown(self::ID, $note) : $this->finding($found, [], 0, 0, false, $note);
    }

    /**
     * @param  array<string, array{severity: string, name: string, reasons: list<string>}>  $found
     * @param  list<string>  $hosts
     */
    private function pins(string $source, int $after, array $found, array $hosts, int $looked, int $dropped): Pins
    {
        $kept = [];

        foreach ($found as $key => $one) {
            $kept[$key] = (string) json_encode($one);
        }

        $kept[self::KEPT_HOSTS] = (string) json_encode($hosts);
        $kept[self::KEPT_COUNTS] = (string) json_encode([$looked, $dropped]);

        return new Pins(self::CURSOR.$source.':'.$after, $kept);
    }

    /**
     * What was already found is said at once, even when the database is not read through yet.
     *
     * @param  array<string, array{severity: string, name: string, reasons: list<string>}>  $found
     * @param  list<string>  $hosts
     */
    private function finding(array $found, array $hosts, int $looked, int $dropped, bool $unfinished, ?Message $note = null): Finding
    {
        $more = $unfinished ? [Message::of('The database is not read through yet: it goes on next time.')] : [];
        $more = $note instanceof Message ? [$note] : $more;

        if ($found === []) {
            if ($unfinished) {
                return Finding::unknown(self::ID, Message::of('The database is not read through yet: it goes on next time.'));
            }

            return Finding::pass(self::ID, Message::of('No disguised script in the database (%d entries holding code were looked at).', $looked), $hosts === [] ? [] : [Message::of('Scripts are loaded from: %s (not judged).', implode(', ', $hosts))]);
        }

        uasort($found, static fn (array $a, array $b): int => Severity::from($b['severity'])->rank() <=> Severity::from($a['severity'])->rank());
        $details = [];

        foreach (array_slice($found, 0, self::LISTED, true) as $key => $one) {
            [$kind, $id] = explode(':', (string) $key, 2);
            $parts = [Message::of('[%s] %s: ', $one['severity'], self::reference($kind, (int) $id, $one['name']))];

            foreach ($one['reasons'] as $number => $reason) {
                if ($number > 0) {
                    $parts[] = Message::raw('; ');
                }

                $parts[] = Message::known($reason);
            }

            $details[] = Message::join('', $parts);
        }

        $count = count($found) + $dropped;

        if ($count > self::LISTED) {
            $details[] = Message::of('and %d more', $count - self::LISTED);
        }

        return Finding::fail(
            self::ID,
            Severity::from($found[array_key_first($found)]['severity']),
            Message::plural('%d entry of the database holds a script that looks disguised.', '%d entries of the database hold a script that looks disguised.', $count, $count),
            [...$details, ...$more],
            Message::of('Read each entry in the database or with WP-CLI, not on the page that shows it. If you did not put that code there: remove it, change every password and the salts, and find how it got in. A script you know can be left alone with scanner.ignore_content. Hornet changes nothing.'),
        );
    }

    private static function reference(string $kind, int $id, string $name): Message
    {
        return match ($kind) {
            ContentKind::Post->value => Message::of('post #%d', $id),
            ContentKind::Comment->value => Message::of('comment #%d', $id),
            ContentKind::Profile->value => Message::of('user #%d', $id),
            default => $name !== '' ? Message::of('option %s', $name) : Message::of('option #%d', $id),
        };
    }

    /**
     * The name of an option goes into a finding, which may reach a chat or an agent: only a plain identifier does.
     */
    private static function safeName(string $name): string
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $name) === 1 ? $name : '';
    }

    private function ignored(ContentEntry $entry): bool
    {
        $kind = match ($entry->kind) {
            ContentKind::SiteIdentity => ContentKind::Option->value,
            ContentKind::Profile => 'user',
            default => $entry->kind->value,
        };

        return in_array($kind.':'.($kind === ContentKind::Option->value ? $entry->name : $entry->id), $this->ignored, true);
    }
}
