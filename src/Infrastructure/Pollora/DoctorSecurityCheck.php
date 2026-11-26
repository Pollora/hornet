<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Pollora;

use Closure;
use Pollora\Doctor\Domain\Contracts\CheckInterface;
use Pollora\Doctor\Domain\Enums\RunContext;
use Pollora\Doctor\Domain\Models\CheckResult;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * The security audit, as one line of `pollora:doctor`.
 *
 * It lives in this package, not in the framework: it only exists when pollora/framework is installed,
 * and the provider registers it only then. The doctor's contract holds: it reads, it never writes, it never asks the network.
 * It runs from the command line; Site Health already has one test per Hornet check.
 *
 * @internal
 */
final readonly class DoctorSecurityCheck implements CheckInterface
{
    /** The most findings the details list before it counts the rest. */
    private const int LISTED = 10;

    /**
     * @param  Closure(): Report  $report
     */
    public function __construct(private Closure $report) {}

    public function id(): string
    {
        return 'hornet-security';
    }

    public function label(): string
    {
        return 'Security (Hornet)';
    }

    public function runsIn(): array
    {
        return [RunContext::Console];
    }

    public function run(RunContext $context): CheckResult
    {
        $report = ($this->report)();
        $open = $report->open();

        if ($open === []) {
            $counts = $report->counts();

            return CheckResult::ok(sprintf('%d of %d security checks pass.', $counts['pass'], $counts['total']), $counts['unknown'] > 0 ? [sprintf('%d could not be verified.', $counts['unknown'])] : []);
        }

        $details = array_map(static fn (Finding $finding): string => sprintf('[%s] %s', $finding->severity->value, $finding->summary), array_slice($open, 0, self::LISTED));

        if (count($open) > self::LISTED) {
            $details[] = sprintf('… and %d more.', count($open) - self::LISTED);
        }

        $fix = ($open[0]->fix ?? 'Run `wp hornet status` for the detail.').' (`wp hornet status` lists every finding.)';
        $summary = sprintf('%d open security finding(s).', count($open));

        return $report->hasOpenAtLeast(Severity::High)
            ? CheckResult::error($summary, $details, $fix)
            : CheckResult::warning($summary, $details, $fix);
    }
}
