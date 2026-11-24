<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * A PHP branch that no longer gets security fixes is a hole that stays open.
 */
final readonly class PhpVersionCheck implements Check
{
    /**
     * The last day each branch gets security fixes. Source: https://www.php.net/supported-versions.php, read on 2026-11-24.
     * Update it when a branch is released.
     */
    private const array SECURITY_SUPPORT_UNTIL = [
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    /** Days before the end of support from which the check says so. */
    private const int WARNING_DAYS = 180;

    public function id(): string
    {
        return 'php-version';
    }

    public function label(): string
    {
        return 'PHP version';
    }

    public function run(Environment $environment): Finding
    {
        $parts = explode('.', $environment->phpVersion());
        $branch = ($parts[0] ?? '0').'.'.($parts[1] ?? '0');

        if (version_compare($branch, '8.2', '<')) {
            return Finding::fail($this->id(), Severity::High, sprintf('PHP %s no longer gets security fixes.', $branch), [], 'Move the site to PHP 8.3 or later.');
        }

        $until = self::SECURITY_SUPPORT_UNTIL[$branch] ?? null;

        if ($until === null) {
            return Finding::unknown($this->id(), sprintf('PHP %s is newer than the support table of this version of Hornet.', $branch));
        }

        $end = $environment->now()->setTime(0, 0)->modify($until.' 23:59:59');
        $days = (int) floor(($end->getTimestamp() - $environment->now()->getTimestamp()) / 86400);

        if ($days < 0) {
            return Finding::fail($this->id(), Severity::High, sprintf('PHP %s no longer gets security fixes (since %s).', $branch, $until), [], 'Move the site to a supported PHP branch.');
        }

        if ($days <= self::WARNING_DAYS) {
            return Finding::fail($this->id(), Severity::Low, sprintf('PHP %s gets security fixes until %s.', $branch, $until), [], 'Plan the move to a newer PHP branch.');
        }

        return Finding::pass($this->id(), sprintf('PHP %s gets security fixes until %s.', $branch, $until));
    }
}
