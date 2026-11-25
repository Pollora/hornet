<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Login;

/**
 * What is known of one address: its recent failures, and whether it is locked out.
 */
final readonly class Attempts
{
    public function __construct(
        public int $failures = 0,
        public int $windowStart = 0,
        public int $lockedUntil = 0,
        public int $lockouts = 0,
    ) {}

    /**
     * @return array{failures: int, window_start: int, locked_until: int, lockouts: int}
     */
    public function toArray(): array
    {
        return ['failures' => $this->failures, 'window_start' => $this->windowStart, 'locked_until' => $this->lockedUntil, 'lockouts' => $this->lockouts];
    }

    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            return new self;
        }

        $number = static fn (string $key): int => is_int($data[$key] ?? null) && $data[$key] >= 0 ? $data[$key] : 0;

        return new self($number('failures'), $number('window_start'), $number('locked_until'), $number('lockouts'));
    }
}
