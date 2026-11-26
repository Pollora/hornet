<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Closure;
use DateInterval;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\PluginInventory;
use Pollora\Hornet\Domain\Scan\Software;
use Pollora\Hornet\Domain\Scan\Vulnerability;
use Pollora\Hornet\Domain\Scan\VulnerabilityFeed;

/**
 * Does a version that the site runs have a known vulnerability?
 *
 * For the WordPress core, each plugin and each theme, the feed is asked about that version. What leaves the site is the name of the
 * software and its version, never the address of the site. It is off until the owner turns it on, because the list of plugins is what
 * an attacker would like to have. An answer that is missing is not "no vulnerability": the finding says what it could not ask.
 */
final readonly class KnownVulnerabilitiesTask implements ScanTask
{
    public const string ID = 'vulnerabilities';

    private const int LISTED = 15;

    /**
     * @param  Closure(): list<string>  $stale  what was answered from an old copy because the feed was down
     * @param  int|null  $budget  seconds after which what is left is not asked; null for no limit
     */
    public function __construct(
        private VulnerabilityFeed $feed,
        private PluginInventory $plugins,
        private PluginInventory $themes,
        private string $coreVersion,
        private Clock $clock,
        private Closure $stale,
        private ?int $budget = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(?Pins $pinned, bool $repin): TaskResult
    {
        $deadline = $this->budget === null ? null : $this->clock->now()->add(new DateInterval('PT'.$this->budget.'S'));
        $asked = [[Software::Core, '', $this->coreVersion, Message::raw('WordPress '.$this->coreVersion)]];

        foreach ($this->plugins->plugins() as $plugin) {
            $asked[] = [Software::Plugin, $plugin->slug, $plugin->version, Message::of('%s %s (plugin)', $plugin->slug, $plugin->version)];
        }

        foreach ($this->themes->plugins() as $theme) {
            $asked[] = [Software::Theme, $theme->slug, $theme->version, Message::of('%s %s (theme)', $theme->slug, $theme->version)];
        }

        $found = [];
        $answered = 0;
        $unanswered = [];

        foreach ($asked as $index => [$software, $slug, $version, $label]) {
            if ($deadline instanceof \DateTimeImmutable && $this->clock->now() > $deadline) {
                $unanswered[] = Message::of('%d more (out of time)', count($asked) - $index);

                break;
            }

            try {
                $items = $this->feed->of($software, $slug, $version);
            } catch (TransportException) {
                $unanswered[] = $label;

                continue;
            }

            $answered++;

            foreach ($items as $vulnerability) {
                $found[] = [$label, $vulnerability];
            }
        }

        if ($answered === 0) {
            return new TaskResult(Finding::unknown(self::ID, Message::of('The vulnerability feed did not answer: nothing was checked.')));
        }

        $notes = [
            ...($unanswered === [] ? [] : [Message::of('Not checked: %s', Message::join(', ', $unanswered))]),
            ...(($stale = ($this->stale)()) === [] ? [] : [Message::of('From a copy of a few days ago, the feed being down: %s', implode(', ', $stale))]),
        ];

        return new TaskResult($this->finding($found, $answered, $notes));
    }

    /**
     * @param  list<array{Message, Vulnerability}>  $found
     * @param  list<Message>  $notes
     */
    private function finding(array $found, int $answered, array $notes): Finding
    {
        if ($found === []) {
            return Finding::pass(self::ID, Message::of('No known vulnerability in the %d versions that were asked about.', $answered), $notes);
        }

        usort($found, static fn (array $a, array $b): int => $b[1]->severity->rank() <=> $a[1]->severity->rank() ?: strcmp($a[0]->english(), $b[0]->english()));
        $lines = array_map(static fn (array $one): Message => Message::of('[%s] %s: %s (%s)%s', $one[1]->severity->value, $one[0], $one[1]->title, $one[1]->id, self::advice($one[1])), array_slice($found, 0, self::LISTED));
        $more = count($found) > self::LISTED ? [Message::of('and %d more', count($found) - self::LISTED)] : [];

        return Finding::fail(
            self::ID,
            $found[0][1]->severity,
            Message::plural('%d known vulnerability in what the site runs.', '%d known vulnerabilities in what the site runs.', count($found), count($found)),
            [...$lines, ...$more, ...$notes],
            Message::of('Update what is listed to the version that fixes it. Where nothing fixes it yet, turn the plugin off or replace it: a vulnerability that is public is being tried on sites like yours. Hornet changes nothing.'),
        );
    }

    private static function advice(Vulnerability $vulnerability): Message
    {
        return match (true) {
            $vulnerability->fixedIn !== null => Message::of(', fixed in %s', $vulnerability->fixedIn),
            $vulnerability->unfixed => Message::of(', not fixed'),
            default => Message::raw(''),
        };
    }
}
