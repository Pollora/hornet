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
use Pollora\Hornet\Domain\Scan\Advisory;
use Pollora\Hornet\Domain\Scan\AdvisoryDatabase;
use Pollora\Hornet\Domain\Scan\ComposerInventory;
use Pollora\Hornet\Domain\Scan\ComposerPackage;
use Pollora\Hornet\Domain\Scan\ComposerPackages;
use Pollora\Hornet\Domain\Scan\Pins;

/**
 * Does a Composer package of the project, at the installed release, have a known vulnerability?
 *
 * Only packages that come from Packagist are asked about: the name of a private package says things about its owner, and no
 * public database knows it. Development packages are included when they are installed (their files are on the server, and some of
 * the worst flaws were in test tools left there) and marked as such. An answer that is missing is never "no vulnerability".
 *
 * @internal
 */
final readonly class ComposerVulnerabilitiesTask implements ScanTask
{
    public const string ID = 'composer-vulnerabilities';

    private const int BATCH = 100;

    private const int LISTED = 15;

    /**
     * @param  Closure(): int  $fetched  how many answers came from the database (not from a cache) so far
     * @param  int|null  $budget  seconds after which what is left waits for a run that goes on; null for no limit
     */
    public function __construct(
        private AdvisoryDatabase $database,
        private ComposerPackages $packages,
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
        $inventory = $this->packages->inventory();

        if (! $inventory instanceof ComposerInventory) {
            return new TaskResult(Finding::unknown(self::ID, Message::of('No list of installed Composer packages could be read: nothing was checked.')));
        }

        $deadline = $this->budget === null ? null : $this->clock->now()->add(new DateInterval('PT'.$this->budget.'S'));
        $found = [];
        $asked = 0;
        $failed = 0;
        $later = 0;
        $unread = false;
        /** @var array<string, Advisory|null> $records */
        $records = [];

        foreach (array_chunk($inventory->packages, self::BATCH) as $batch) {
            if ($this->isLate($deadline)) {
                $later += count($batch);

                continue;
            }

            try {
                $matches = $this->database->affecting($batch);
            } catch (TransportException) {
                $failed += count($batch);

                continue;
            }

            $asked += count($batch);

            foreach ($batch as $index => $package) {
                $advisories = [];

                foreach ($matches[$index] ?? [] as $id => $modified) {
                    $id = (string) $id;

                    if (! array_key_exists($id, $records)) {
                        $records[$id] = null;

                        if ($this->isLate($deadline)) {
                            $later++;
                        } else {
                            try {
                                $records[$id] = $this->database->advisory($id, $modified);
                            } catch (TransportException) {
                                $unread = true;
                            }
                        }
                    }

                    // The database said it concerns this release: it is told even when the record itself could not be read
                    $advisory = $records[$id] ?? new Advisory($id, [], '', Severity::Medium);

                    if (! $advisory->withdrawn) {
                        $advisories[] = $advisory;
                    }
                }

                foreach ($this->flaws($advisories) as $group) {
                    $found[] = $this->line($package, $group);
                }
            }
        }

        $unfinished = $later > 0 && ($this->fetched)() > 0;
        $missing = [
            ...($failed === 0 ? [] : [Message::plural('api.osv.dev did not answer for %d package.', 'api.osv.dev did not answer for %d packages.', $failed, $failed)]),
            ...($later === 0 ? [] : [Message::of('Not everything was read in the time allowed.')]),
            ...($unread ? [Message::of('Some records could not be read: their severity and their fix are not known.')] : []),
        ];
        $notes = $this->notes($inventory);

        if ($found !== []) {
            return new TaskResult($this->failure($found, [...$missing, ...$notes]), unfinished: $unfinished);
        }

        if ($missing !== []) {
            return new TaskResult(Finding::unknown(self::ID, Message::join(' ', $missing)), unfinished: $unfinished);
        }

        if ($asked === 0) {
            return new TaskResult($notes === []
                ? Finding::pass(self::ID, Message::of('The project has no Composer package installed.'))
                : Finding::unknown(self::ID, Message::join(' ', [Message::of('No Composer package could be asked about.'), ...$notes])));
        }

        return new TaskResult(Finding::pass(self::ID, Message::plural('No known vulnerability in the %d Composer package asked about.', 'No known vulnerability in the %d Composer packages asked about.', $asked, $asked), $notes));
    }

    /**
     * @phpstan-impure the clock moves between two calls
     */
    private function isLate(?DateTimeImmutable $deadline): bool
    {
        return $deadline instanceof DateTimeImmutable && $this->clock->now() > $deadline;
    }

    /**
     * Two databases may file the same flaw: records of which one names the other are then one. Never two GitHub records:
     * GitHub files one per flaw, yet their aliases do cross (a grouped Drupal advisory names two different Guzzle flaws in
     * each of them), and merging on that would hide one.
     *
     * @param  list<Advisory>  $advisories
     * @return list<non-empty-list<Advisory>>
     */
    private function flaws(array $advisories): array
    {
        $groups = [];

        foreach ($advisories as $advisory) {
            foreach ($groups as $key => $group) {
                if ($this->sameFlaw($group, $advisory)) {
                    $groups[$key][] = $advisory;

                    continue 2;
                }
            }

            $groups[] = [$advisory];
        }

        return array_values($groups);
    }

    /**
     * @param  non-empty-list<Advisory>  $group
     */
    private function sameFlaw(array $group, Advisory $advisory): bool
    {
        $github = static fn (Advisory $one): bool => str_starts_with($one->id, 'GHSA-');

        if ($github($advisory) && array_filter($group, $github) !== []) {
            return false;
        }

        foreach ($group as $member) {
            if (in_array($member->id, $advisory->aliases, true) || in_array($advisory->id, $member->aliases, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  non-empty-list<Advisory>  $group
     * @return array{Severity, string, Message}
     */
    private function line(ComposerPackage $package, array $group): array
    {
        usort($group, static fn (Advisory $a, Advisory $b): int => $b->severity->rank() <=> $a->severity->rank() ?: strcmp($a->id, $b->id));
        $fixes = [];

        foreach ($group as $advisory) {
            if (($fix = $advisory->fixedFor($package->name, $package->version)) !== null) {
                $fixes[] = $fix;
            }
        }

        // The CVE number when there is just one: a record that lists several does not say which one is its own
        $numbers = array_values(array_unique(array_filter([$group[0]->id, ...$group[0]->aliases], static fn (string $name): bool => preg_match('/^CVE-\d{4}-\d{4,}$/D', $name) === 1)));
        usort($fixes, static fn (string $a, string $b): int => version_compare($a, $b) <=> 0);
        $severity = $group[0]->severity;
        $label = $package->dev ? Message::of('%s %s (development package)', $package->name, $package->version) : Message::raw($package->name.' '.$package->version);
        $title = $group[0]->summary === '' ? Message::of('a vulnerability the database does not describe') : Message::raw($group[0]->summary);

        return [$severity, $package->name, Message::of('[%s] %s: %s (%s)%s', $severity->value, $label, $title, count($numbers) === 1 ? $numbers[0] : $group[0]->id, $fixes === [] ? Message::raw('') : Message::of(', fixed in %s', $fixes[array_key_last($fixes)]))];
    }

    /**
     * @return list<Message>
     */
    private function notes(ComposerInventory $inventory): array
    {
        return [
            ...($inventory->branches === [] ? [] : [Message::of('Installed from a branch, so not checked: %s', implode(', ', array_slice($inventory->branches, 0, self::LISTED)).(count($inventory->branches) > self::LISTED ? ', …' : ''))]),
            ...($inventory->elsewhere === 0 ? [] : [Message::plural('%d package does not come from Packagist: its name was not sent and it was not checked.', '%d packages do not come from Packagist: their names were not sent and they were not checked.', $inventory->elsewhere, $inventory->elsewhere)]),
        ];
    }

    /**
     * @param  non-empty-list<array{Severity, string, Message}>  $found
     * @param  list<Message>  $notes
     */
    private function failure(array $found, array $notes): Finding
    {
        usort($found, static fn (array $a, array $b): int => $b[0]->rank() <=> $a[0]->rank() ?: strcmp($a[1], $b[1]));
        $more = count($found) > self::LISTED ? [Message::of('and %d more', count($found) - self::LISTED)] : [];

        return Finding::fail(
            self::ID,
            $found[0][0],
            Message::plural('%d known vulnerability in the Composer packages of the project.', '%d known vulnerabilities in the Composer packages of the project.', count($found), count($found)),
            [...array_column(array_slice($found, 0, self::LISTED), 2), ...$more, ...$notes],
            Message::of('Run `composer update` for the packages listed, to a release that fixes them, then test and deploy. A development package has no place on a production server: install with `composer install --no-dev`. Hornet changes nothing.'),
        );
    }
}
