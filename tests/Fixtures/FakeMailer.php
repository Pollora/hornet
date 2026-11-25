<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Notification\Mailer;

final class FakeMailer implements Mailer
{
    /** @var list<array{to: list<string>, subject: string, body: string}> */
    public array $mails = [];

    public bool $works = true;

    public function send(array $to, string $subject, string $body): bool
    {
        $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

        return $this->works;
    }
}
