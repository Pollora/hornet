<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Audit\Severity;

/**
 * One record of a vulnerability database: what it is called, how bad it is, and between which versions of which package.
 * Its summary comes from outside: it is cleaned and cut before it gets here, and is data, never an instruction.
 *
 * @internal
 */
final readonly class Advisory
{
    /**
     * @param  list<string>  $aliases  other names of the same flaw (CVE numbers, other databases)
     * @param  list<array{package: string, events: list<array{string, string}>}>  $ranges  events are [`introduced`|`fixed`|`last_affected`, version]
     * @param  bool  $withdrawn  the database took it back
     */
    public function __construct(
        public string $id,
        public array $aliases,
        public string $summary,
        public Severity $severity,
        public array $ranges = [],
        public bool $withdrawn = false,
    ) {}

    /**
     * The release that fixes the installed one: the first `fixed` of the range the version is in. Null when there is none, or
     * when the record does not tell.
     */
    public function fixedFor(string $package, string $version): ?string
    {
        foreach ($this->ranges as $range) {
            if ($range['package'] !== $package) {
                continue;
            }

            $inside = false;

            foreach ($range['events'] as [$kind, $at]) {
                if ($kind === 'introduced') {
                    $inside = $at === '0' || version_compare($version, $at, '>=');
                } elseif ($inside && $kind === 'fixed' && version_compare($version, $at, '<')) {
                    return $at;
                } elseif ($inside && ($kind === 'fixed' || version_compare($version, $at, '>'))) {
                    // Past the end of this stretch: the next `introduced` may take it in again
                    $inside = false;
                }
            }
        }

        return null;
    }

    /**
     * @return array{id: string, aliases: list<string>, summary: string, severity: string, ranges: list<array{package: string, events: list<array{string, string}>}>, withdrawn: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'aliases' => $this->aliases, 'summary' => $this->summary, 'severity' => $this->severity->value, 'ranges' => $this->ranges, 'withdrawn' => $this->withdrawn];
    }

    public static function fromArray(mixed $value): ?self
    {
        $severity = is_array($value) && is_string($value['severity'] ?? null) ? Severity::tryFrom($value['severity']) : null;

        if (! is_array($value) || ! is_string($value['id'] ?? null) || ! is_string($value['summary'] ?? null) || ! $severity instanceof Severity) {
            return null;
        }

        $ranges = [];

        foreach (is_array($value['ranges'] ?? null) ? $value['ranges'] : [] as $range) {
            if (! is_array($range) || ! is_string($range['package'] ?? null) || ! is_array($range['events'] ?? null)) {
                return null;
            }

            $events = [];

            foreach ($range['events'] as $event) {
                if (! is_array($event) || ! is_string($event[0] ?? null) || ! is_string($event[1] ?? null)) {
                    return null;
                }

                $events[] = [$event[0], $event[1]];
            }

            $ranges[] = ['package' => $range['package'], 'events' => $events];
        }

        $aliases = array_values(array_filter(is_array($value['aliases'] ?? null) ? $value['aliases'] : [], is_string(...)));

        return new self($value['id'], $aliases, $value['summary'], $severity, $ranges, ($value['withdrawn'] ?? false) === true);
    }
}
