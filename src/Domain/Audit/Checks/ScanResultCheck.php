<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use DateInterval;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;

/**
 * What the last scan found for one scan task.
 *
 * The audit reads and never scans: it does not walk the files nor ask the network (the contract of a check). The scan runs
 * on its own (`wp hornet scan`), and this check shows its result, so that Site Health and `pollora:doctor` tell the same story.
 */
final readonly class ScanResultCheck implements Check
{
    /** A clean result that old says little about the files today. */
    private const int STALE_AFTER_DAYS = 7;

    public function __construct(
        private string $id,
        private string $label,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function run(Environment $environment): Finding
    {
        $snapshot = $environment->lastScan();

        if (! $snapshot instanceof ScanSnapshot) {
            return Finding::unknown($this->id, Message::of('The site was never scanned: run `wp hornet scan`.'));
        }

        $finding = $snapshot->finding($this->id);

        if (! $finding instanceof Finding) {
            return Finding::unknown($this->id, Message::of('The last scan did not cover this: run `wp hornet scan`.'));
        }

        $limit = $environment->now()->sub(new DateInterval('P'.self::STALE_AFTER_DAYS.'D'));

        if ($finding->outcome === Outcome::Pass && $snapshot->at < $limit) {
            return Finding::fail($this->id, Severity::Low, Message::plural('The last scan is more than %d day old, so it says little about the files today.', 'The last scan is more than %d days old, so it says little about the files today.', self::STALE_AFTER_DAYS, self::STALE_AFTER_DAYS), [Message::of('Last scan: %s UTC', $snapshot->at->format('Y-m-d H:i'))], Message::of('Run `wp hornet scan`.'));
        }

        return $finding;
    }
}
