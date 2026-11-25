<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Journal;

use DateTimeImmutable;

/**
 * One thing that happened: when, what, from where, by whom, to what.
 *
 * The address is already cut when it gets here, and the details already cleaned: an event never holds more than it should.
 */
final readonly class Event
{
    /**
     * @param  int  $userId  who did it, or 0 when nobody is known (a visitor, a cron run)
     * @param  string  $actor  the login of that person, as it was when it happened
     * @param  string  $subject  what it is about (`user:12`, `plugin:akismet`, `option:siteurl`)
     * @param  string  $ip  the address, cut (see {@see IpAnonymizer})
     * @param  array<string, string|int|bool>  $details
     */
    public function __construct(
        public DateTimeImmutable $at,
        public string $type,
        public Source $source,
        public int $userId = 0,
        public string $actor = '',
        public string $subject = '',
        public string $ip = '',
        public array $details = [],
        public ?int $id = null,
    ) {}

    /**
     * @return array{id: ?int, at: int, type: string, source: string, user_id: int, actor: string, subject: string, ip: string, details: array<string, string|int|bool>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'at' => $this->at->getTimestamp(), 'type' => $this->type, 'source' => $this->source->value, 'user_id' => $this->userId, 'actor' => $this->actor, 'subject' => $this->subject, 'ip' => $this->ip, 'details' => $this->details];
    }
}
