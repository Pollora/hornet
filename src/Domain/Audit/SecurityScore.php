<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * Where the site stands, as one number out of 100 and a grade from A to F. It summarises the findings; it is not a promise of safety,
 * and it counts only what the audit looked at.
 *
 * The rule, kept simple enough to be explained to whoever reads the grade:
 * - each open finding takes points according to its severity ({@see self::COST});
 * - the grade is held back by the worst that is open, whatever the total: a critical finding cannot read better than E, a high one
 *   than C. A site with one open door is not "good" because everything else is shut;
 * - a high or critical finding that was set aside costs no points, and keeps the grade from A: an accepted risk is still there;
 * - what could not be verified costs nothing, and the score says it is provisional.
 *
 * @internal
 */
final readonly class SecurityScore
{
    /** Points an open finding takes. */
    private const array COST = ['critical' => 40, 'high' => 20, 'medium' => 8, 'low' => 3, 'info' => 1];

    /** The best score an open finding of this severity leaves. */
    private const array CEILING = ['critical' => 39, 'high' => 74];

    private const int ACCEPTED_RISK_CEILING = 89;

    private const array GRADES = ['A' => 90, 'B' => 75, 'C' => 60, 'D' => 40, 'E' => 20, 'F' => 0];

    /**
     * @param  array<string, int>  $costs  the points each open finding takes, by check, the most expensive first
     */
    private function __construct(
        public int $value,
        public string $grade,
        public array $costs,
        public int $verified,
        public int $total,
        public int $accepted,
    ) {}

    public static function of(Report $report): self
    {
        $costs = [];
        $ceiling = 100;
        $accepted = 0;
        $unknown = 0;

        foreach ($report->findings as $finding) {
            if ($finding->outcome === Outcome::Unknown) {
                $unknown++;

                continue;
            }

            if ($finding->outcome !== Outcome::Fail) {
                continue;
            }

            $severity = $finding->severity->value;

            if ($finding->isIgnored()) {
                $accepted++;

                if (isset(self::CEILING[$severity])) {
                    $ceiling = min($ceiling, self::ACCEPTED_RISK_CEILING);
                }

                continue;
            }

            $costs[$finding->checkId] = self::COST[$severity];
            $ceiling = min($ceiling, self::CEILING[$severity] ?? 100);
        }

        arsort($costs);
        $value = max(0, min($ceiling, 100 - array_sum($costs)));
        $grade = 'F';

        foreach (self::GRADES as $letter => $from) {
            if ($value >= $from) {
                $grade = $letter;
                break;
            }
        }

        return new self($value, $grade, $costs, count($report->findings) - $unknown, count($report->findings), $accepted);
    }

    /**
     * Whether part of the audit could not be done: the score then speaks of less than the whole.
     */
    public function isProvisional(): bool
    {
        return $this->verified < $this->total;
    }

    /**
     * @return array{value: int, grade: string, provisional: bool, verified: int, total: int, accepted: int, costs: array<string, int>}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'grade' => $this->grade, 'provisional' => $this->isProvisional(), 'verified' => $this->verified, 'total' => $this->total, 'accepted' => $this->accepted, 'costs' => $this->costs];
    }
}
