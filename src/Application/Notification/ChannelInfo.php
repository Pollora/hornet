<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Notification;

use Pollora\Hornet\Domain\Audit\Severity;

/**
 * What a person may be told about a channel: its name, its kind, how loud. Never where it goes.
 *
 * @internal
 */
final readonly class ChannelInfo
{
    public function __construct(
        public string $name,
        public string $type,
        public Severity $minSeverity,
    ) {}
}
