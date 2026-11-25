<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application;

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Notification\Alert;

/**
 * Turns the findings of an audit into alerts.
 *
 * The key holds the severity: a finding that gets worse is news again, one that stays the same is not.
 */
final class AlertFactory
{
    /**
     * @return list<Alert>
     */
    public static function fromReport(Report $report): array
    {
        return array_map(
            static fn (Finding $finding): Alert => new Alert('finding:'.$finding->checkId.':'.$finding->severity->value, $finding->severity, $finding->summary, $finding->details, $finding->fix),
            $report->open(),
        );
    }
}
