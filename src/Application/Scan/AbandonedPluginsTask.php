<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\PluginDirectory;
use Pollora\Hornet\Domain\Scan\PluginInventory;
use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Domain\Scan\PluginListingStatus;

/**
 * Has WordPress.org closed a plugin the site has, or has its author stopped publishing it?
 *
 * Closed is a strong finding: nothing more will come, and the reason is often a flaw. Two years without a release is a weak
 * one: small plugins can be finished. A folder name alone is not enough to say either of them: the plugin must also have the
 * name of the listing, and not say it is updated elsewhere. Whatever could not be asked is "unknown", never a pass.
 *
 * @internal
 */
final readonly class AbandonedPluginsTask implements ScanTask
{
    public const string ID = 'abandoned-plugins';

    private const int YEARS = 2;

    private const int LISTED = 15;

    /**
     * @param  Closure(): int  $fetched  how many answers came from the directory (not from a cache) so far
     * @param  int|null  $budget  seconds after which what is left waits for a run that goes on; null for no limit
     */
    public function __construct(
        private PluginDirectory $directory,
        private PluginInventory $plugins,
        private Clock $clock,
        private Closure $fetched,
        private ?int $budget = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(?Pins $pinned, bool $repin): TaskResult
    {
        $now = $this->clock->now();
        $deadline = $this->budget === null ? null : $now->add(new DateInterval('PT'.$this->budget.'S'));
        $old = $now->sub(new DateInterval('P'.self::YEARS.'Y'));
        $plugins = $this->plugins->plugins();
        $asked = array_values(array_filter($plugins, static fn (InstalledPlugin $plugin): bool => ! $plugin->updatedElsewhere()));
        $elsewhere = count($plugins) - count($asked);
        $closed = [];
        $abandoned = [];
        $others = [];
        $unchecked = [];
        $known = 0;
        $later = 0;

        foreach ($asked as $index => $plugin) {
            if ($deadline instanceof DateTimeImmutable && $this->clock->now() > $deadline) {
                $later = count($asked) - $index;

                break;
            }

            try {
                $listing = $this->directory->of($plugin->slug);
            } catch (TransportException) {
                $unchecked[] = $plugin->slug;

                continue;
            }

            if ($listing->status === PluginListingStatus::Absent) {
                continue;
            }

            if (! $listing->isSamePlugin($plugin)) {
                // Only worth a word when the listing under that folder name would have been a finding
                if ($listing->status === PluginListingStatus::Closed || $this->isOld($listing, $old)) {
                    $others[] = $plugin->slug;
                }

                continue;
            }

            if ($listing->status === PluginListingStatus::Closed) {
                $closed[] = $this->closedLine($plugin->slug, $listing);
            } elseif ($listing->updatedAt === null) {
                $unchecked[] = $plugin->slug;

                continue;
            } elseif ($this->isOld($listing, $old)) {
                $abandoned[] = [$plugin->slug, Message::of('%s: no release on WordPress.org since %s', $plugin->slug, gmdate('Y-m-d', $listing->updatedAt))];
            }

            $known++;
        }

        // Going on is only worth it when this run moved: a directory that is down must not be asked every minute for ever
        $unfinished = $later > 0 && ($this->fetched)() > 0;
        $missing = [
            ...($unchecked === [] ? [] : [Message::of('WordPress.org could not be asked about: %s', implode(', ', array_slice($unchecked, 0, self::LISTED)).(count($unchecked) > self::LISTED ? ', …' : ''))]),
            ...($later === 0 ? [] : [Message::plural('%d plugin was not reached in the time allowed.', '%d plugins were not reached in the time allowed.', $later, $later)]),
        ];
        $notes = [
            ...($others === [] ? [] : [Message::of('Same folder name as a plugin that is closed or old on WordPress.org, under another name or version, so probably another plugin and not counted: %s', implode(', ', $others))]),
            ...($elsewhere === 0 ? [] : [Message::plural('%d plugin says it is updated from somewhere else and was not asked about.', '%d plugins say they are updated from somewhere else and were not asked about.', $elsewhere, $elsewhere)]),
        ];

        if ($closed !== [] || $abandoned !== []) {
            return new TaskResult($this->failure($closed, $abandoned, [...$missing, ...$notes]), unfinished: $unfinished);
        }

        if ($missing !== []) {
            return new TaskResult(Finding::unknown(self::ID, Message::join(' ', $missing)), unfinished: $unfinished);
        }

        $summary = $known === 0
            ? Message::of('No plugin of this site was recognised on WordPress.org: nothing to say about closed or old plugins.')
            : Message::plural('The %d plugin recognised on WordPress.org is open and has had a release in the last two years.', 'The %d plugins recognised on WordPress.org are open and have had a release in the last two years.', $known, $known);

        return new TaskResult(Finding::pass(self::ID, $summary, $notes));
    }

    private function isOld(PluginListing $listing, DateTimeImmutable $limit): bool
    {
        return $listing->updatedAt !== null && $listing->updatedAt <= $limit->getTimestamp();
    }

    /**
     * @return array{string, Message}
     */
    private function closedLine(string $slug, PluginListing $listing): array
    {
        $reason = Message::known(match ($listing->reason) {
            'security-issue' => Message::mark('security issue'),
            'author-request' => Message::mark('at the request of its author'),
            'guideline-violation' => Message::mark('it broke the rules of the directory'),
            'licensing-trademark-violation' => Message::mark('licence or trademark violation'),
            'merged-into-core' => Message::mark('now part of WordPress'),
            'unused' => Message::mark('not used'),
            default => Message::mark('reason not published'),
        });

        return [$slug, $listing->closedOn === null
            ? Message::of('%s: closed by WordPress.org (%s)', $slug, $reason)
            : Message::of('%s: closed by WordPress.org on %s (%s)', $slug, $listing->closedOn, $reason)];
    }

    /**
     * The first folder names of a list.
     *
     * @param  list<array{string, Message}>  $found
     */
    private function names(array $found): string
    {
        return implode(', ', array_slice(array_column($found, 0), 0, 5));
    }

    /**
     * @param  list<array{string, Message}>  $closed
     * @param  list<array{string, Message}>  $abandoned
     * @param  list<Message>  $notes
     */
    private function failure(array $closed, array $abandoned, array $notes): Finding
    {
        $summary = [];

        if ($closed !== []) {
            $summary[] = Message::plural('%d plugin has been closed by WordPress.org: %s.', '%d plugins have been closed by WordPress.org: %s.', count($closed), count($closed), $this->names($closed));
        }

        if ($abandoned !== []) {
            $summary[] = Message::plural('%d plugin has had no release on WordPress.org for two years or more: %s.', '%d plugins have had no release on WordPress.org for two years or more: %s.', count($abandoned), count($abandoned), $this->names($abandoned));
        }

        $lines = array_column([...$closed, ...$abandoned], 1);
        $more = count($lines) > self::LISTED ? [Message::of('and %d more', count($lines) - self::LISTED)] : [];

        return Finding::fail(
            self::ID,
            $closed === [] ? Severity::Low : Severity::High,
            count($summary) === 1 ? $summary[0] : Message::join(' ', $summary),
            [...array_slice($lines, 0, self::LISTED), ...$more, ...$notes],
            Message::of('Replace a closed plugin: it gets no more updates from WordPress.org, and WordPress does not warn about it. For one with no release for years, check that its author still answers, or plan its replacement. Hornet changes nothing.'),
        );
    }
}
