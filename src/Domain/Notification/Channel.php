<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

use Pollora\Hornet\Domain\Audit\Severity;

/**
 * Somewhere to send alerts: a mailbox, a chat, a webhook.
 *
 * @internal
 */
interface Channel
{
    /** The name the owner gave it in the settings. */
    public function name(): string;

    /** The lowest severity this channel hears about. */
    public function minSeverity(): Severity;

    /** Sends one message. It does not throw for a network or a service problem: it says so in the delivery. */
    public function send(Notification $notification): Delivery;
}
