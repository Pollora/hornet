<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * The findings of one audit.
 *
 * @internal
 */
final readonly class Report
{
    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(public array $findings) {}

    /**
     * The findings that ask for attention, the most serious first.
     *
     * @return list<Finding>
     */
    public function open(): array
    {
        $open = array_values(array_filter($this->findings, static fn (Finding $finding): bool => $finding->isOpen()));
        usort($open, static fn (Finding $a, Finding $b): int => $b->severity->rank() <=> $a->severity->rank());

        return $open;
    }

    public function worst(): ?Severity
    {
        return $this->open()[0]->severity ?? null;
    }

    public function hasOpenAtLeast(Severity $threshold): bool
    {
        foreach ($this->open() as $finding) {
            if ($finding->severity->atLeast($threshold)) {
                return true;
            }
        }

        return false;
    }

    public function find(string $checkId): ?Finding
    {
        foreach ($this->findings as $finding) {
            if ($finding->checkId === $checkId) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @return array{total: int, pass: int, open: int, ignored: int, unknown: int}
     */
    public function counts(): array
    {
        $count = ['total' => count($this->findings), 'pass' => 0, 'open' => 0, 'ignored' => 0, 'unknown' => 0];

        foreach ($this->findings as $finding) {
            match (true) {
                $finding->outcome === Outcome::Pass => $count['pass']++,
                $finding->outcome === Outcome::Unknown => $count['unknown']++,
                $finding->isIgnored() => $count['ignored']++,
                default => $count['open']++,
            };
        }

        return $count;
    }
}
