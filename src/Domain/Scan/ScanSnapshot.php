<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Audit\Finding;

/**
 * What the last scan found and when, kept so that the audit, Site Health and `pollora:doctor` can read it without scanning.
 */
final readonly class ScanSnapshot
{
    /**
     * @param  array<string, Finding>  $findings  by the id of the scan task
     * @param  array<string, Pins>  $pins  by the id of the scan task
     * @param  list<string>  $unfinished  ids of the tasks that stopped on their time budget and go on at the next run
     */
    public function __construct(
        public DateTimeImmutable $at,
        public array $findings,
        public array $pins = [],
        public array $unfinished = [],
    ) {}

    public function finding(string $taskId): ?Finding
    {
        return $this->findings[$taskId] ?? null;
    }

    public function pins(string $taskId): ?Pins
    {
        return $this->pins[$taskId] ?? null;
    }

    /**
     * @return array{at: int, findings: array<string, array<string, mixed>>, pins: array<string, array{scope: string, hashes: array<string, string>}>, unfinished: list<string>}
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at->getTimestamp(),
            'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->findings),
            'pins' => array_map(static fn (Pins $pins): array => $pins->toArray(), $this->pins),
            'unfinished' => $this->unfinished,
        ];
    }

    /**
     * Reads what {@see toArray()} wrote. What is stored in the database may have been edited or cut: anything that does not fit is left out.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value) || ! is_int($value['at'] ?? null) || ! is_array($value['findings'] ?? null)) {
            return null;
        }

        $findings = [];

        foreach ($value['findings'] as $id => $stored) {
            $finding = Finding::fromArray($stored);

            if ($finding instanceof Finding) {
                $findings[(string) $id] = $finding;
            }
        }

        $pins = [];

        foreach (is_array($value['pins'] ?? null) ? $value['pins'] : [] as $id => $stored) {
            $pin = Pins::fromArray($stored);

            if ($pin instanceof Pins) {
                $pins[(string) $id] = $pin;
            }
        }

        $unfinished = array_values(array_filter(is_array($value['unfinished'] ?? null) ? $value['unfinished'] : [], is_string(...)));

        return new self((new DateTimeImmutable('@'.$value['at']))->setTimezone(new DateTimeZone('UTC')), $findings, $pins, $unfinished);
    }
}
