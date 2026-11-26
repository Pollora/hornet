<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * A finding that someone chose to live with, and why: set aside from the screen, on a reason they wrote.
 *
 * @internal
 */
final readonly class SetAside
{
    public function __construct(
        public string $check,
        public string $reason,
        public int $userId,
        public string $login,
        public int $at,
    ) {}

    /**
     * @return array{reason: string, user: int, login: string, at: int}
     */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'user' => $this->userId, 'login' => $this->login, 'at' => $this->at];
    }

    public static function fromArray(string $check, mixed $value): ?self
    {
        if (! is_array($value) || ! is_string($value['reason'] ?? null) || ! is_int($value['user'] ?? null) || ! is_string($value['login'] ?? null) || ! is_int($value['at'] ?? null)) {
            return null;
        }

        return new self($check, $value['reason'], $value['user'], $value['login'], $value['at']);
    }
}
