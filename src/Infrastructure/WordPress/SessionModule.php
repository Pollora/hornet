<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * How long a login lasts: shorter than WordPress's default of two days, or of fourteen with "remember me", when the owner says so.
 */
final readonly class SessionModule implements Module
{
    public function __construct(
        private ?int $lifetime,
        private ?int $rememberLifetime,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('auth_cookie_expiration', $this->expiration(...), 10, 3);
    }

    public function expiration(int $expiration, int $userId = 0, bool $remember = false): int
    {
        return ($remember ? $this->rememberLifetime : $this->lifetime) ?? $expiration;
    }
}
