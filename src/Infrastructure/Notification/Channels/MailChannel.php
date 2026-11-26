<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification\Channels;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Channel;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\Mailer;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Infrastructure\Notification\Text;

/**
 * An e-mail through the site's own mailer, in plain text.
 *
 * A mailer that says no may be a passing problem (the SMTP server is down), so the message is tried again.
 *
 * @internal
 */
final readonly class MailChannel implements Channel
{
    /**
     * @param  list<string>  $to
     */
    public function __construct(
        private string $name,
        private Severity $minSeverity,
        private Mailer $mailer,
        private array $to,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function minSeverity(): Severity
    {
        return $this->minSeverity;
    }

    public function send(Notification $notification): Delivery
    {
        $body = implode("\n", [
            Text::clean($notification->siteName).' ('.Text::clean($notification->siteUrl).')',
            '',
            ...Text::lines($notification, 500),
            '',
            'Sent by Hornet at '.$notification->createdAt->format(DATE_ATOM).'.',
        ]);

        return $this->mailer->send($this->to, Text::clean($notification->subject()), $body)
            ? Delivery::delivered()
            : Delivery::retry('The site could not send the e-mail.');
    }
}
