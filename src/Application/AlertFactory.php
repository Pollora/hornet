<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application;

use Pollora\Hornet\Application\I18n\FindingTranslator;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\I18n\Translator;
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
    public static function fromReport(Report $report, ?Translator $translator = null): array
    {
        // The key never depends on the language: a change of the site's language must not make old news new
        return array_map(
            static fn (Finding $finding): Alert => new Alert('finding:'.$finding->checkId.':'.$finding->severity->value, $finding->severity, FindingTranslator::summary($finding, $translator), FindingTranslator::details($finding, $translator), FindingTranslator::fix($finding, $translator)),
            $report->open(),
        );
    }
}
