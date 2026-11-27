<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The open sessions of each user, as WP_Session_Tokens gives them.
 */
final class FakeSessionTokens
{
    /** @var array<int, list<array<string, mixed>>> */
    public static array $sessions = [];

    private function __construct(private readonly int $user) {}

    public static function get_instance(int $user): self
    {
        return new self($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get_all(): array
    {
        return self::$sessions[$this->user] ?? [];
    }
}
