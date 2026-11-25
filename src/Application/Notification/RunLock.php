<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Notification;

/**
 * Keeps two runs from notifying at the same time (the cron and a command, two cron runs): the second one steps aside.
 */
interface RunLock
{
    public function acquire(): bool;

    public function release(): void;
}
