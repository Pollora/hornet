<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

/**
 * Sends a plain-text e-mail.
 *
 * @internal
 */
interface Mailer
{
    /**
     * @param  list<string>  $to
     */
    public function send(array $to, string $subject, string $body): bool;
}
