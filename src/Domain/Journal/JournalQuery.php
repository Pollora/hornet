<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Journal;

use DateTimeImmutable;

/**
 * Which events are wanted: all of them, newest first, unless this narrows it.
 */
final readonly class JournalQuery
{
    public const int MAX = 200;

    public int $limit;

    public int $offset;

    /**
     * @param  string|null  $type  one type (`login.failed`), or a whole group with a trailing dot (`login.`)
     * @param  int  $limit  at most {@see MAX}
     */
    public function __construct(
        public ?string $type = null,
        public ?Source $source = null,
        public ?int $userId = null,
        public ?DateTimeImmutable $since = null,
        int $limit = 50,
        int $offset = 0,
    ) {
        $this->limit = max(1, min(self::MAX, $limit));
        $this->offset = max(0, $offset);
    }
}
