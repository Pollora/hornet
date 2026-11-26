<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Notification;

/**
 * The result for one channel. The reason never holds a URL or a token.
 *
 * @internal
 */
final readonly class ChannelOutcome
{
    public function __construct(
        public string $channel,
        public ChannelStatus $status,
        public int $alerts = 0,
        public string $reason = '',
    ) {}
}
