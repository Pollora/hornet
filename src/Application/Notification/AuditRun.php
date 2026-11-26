<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Notification;

use Pollora\Hornet\Domain\Audit\Report;

/**
 * An audit and what was told about it. `$report` is null when another run held the lock and this one stepped aside.
 *
 * @internal
 */
final readonly class AuditRun
{
    /**
     * @param  list<ChannelOutcome>  $outcomes
     */
    public function __construct(
        public ?Report $report,
        public array $outcomes,
    ) {}
}
