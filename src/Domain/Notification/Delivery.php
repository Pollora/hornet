<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

/**
 * What came of one attempt to send.
 *
 * A failure says whether trying again can help: a network error, a 429 or a 5xx can; a 4xx that is not a 429 (a wrong URL,
 * a revoked token) cannot, and retrying it would only repeat the mistake. The reason never contains a URL or a token.
 *
 * @internal
 */
final readonly class Delivery
{
    private function __construct(
        public bool $delivered,
        public bool $retryable,
        public ?int $retryAfter,
        public string $reason,
    ) {}

    public static function delivered(): self
    {
        return new self(true, false, null, '');
    }

    public static function retry(string $reason, ?int $retryAfter = null): self
    {
        return new self(false, true, $retryAfter, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(false, false, null, $reason);
    }

    /**
     * What an HTTP status means for a webhook-style channel.
     */
    public static function fromStatus(int $status, ?int $retryAfter = null): self
    {
        return match (true) {
            $status >= 200 && $status < 300 => self::delivered(),
            $status === 429 || $status === 408 => self::retry('The service asked to slow down (HTTP '.$status.').', $retryAfter),
            $status >= 500 => self::retry('The service failed (HTTP '.$status.').', $retryAfter),
            default => self::failed('The service refused the message (HTTP '.$status.').'),
        };
    }
}
