<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * Whether a scan is waiting or running, and which task it is at: what the administration screen shows while it waits.
 */
final readonly class ScanState
{
    public const string IDLE = 'idle';

    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    /** A scan that says it runs for longer than this has died: it is not waited for. */
    private const int STALE_AFTER = 900;

    public function __construct(
        public string $status = self::IDLE,
        public ?string $task = null,
        public int $since = 0,
    ) {}

    public static function idle(): self
    {
        return new self;
    }

    /**
     * Whether a scan is in progress now: waiting or running, and not for so long that it can only have died.
     */
    public function isActive(int $now): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true) && $now - $this->since < self::STALE_AFTER;
    }

    /**
     * @return array{status: string, task: ?string, since: int}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'task' => $this->task, 'since' => $this->since];
    }

    public static function fromArray(mixed $value): self
    {
        if (! is_array($value) || ! in_array($value['status'] ?? null, [self::IDLE, self::QUEUED, self::RUNNING], true) || ! is_int($value['since'] ?? null)) {
            return self::idle();
        }

        return new self($value['status'], is_string($value['task'] ?? null) ? $value['task'] : null, $value['since']);
    }
}
