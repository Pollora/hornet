<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Notification\Mailer;

/**
 * `wp_mail()`: the site's own mail setup, whatever it is (SMTP plugin, transactional service).
 *
 * @internal
 */
final class WpMailer implements Mailer
{
    public function send(array $to, string $subject, string $body): bool
    {
        // A line break in a subject is how a header is injected
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));

        return wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
    }
}
