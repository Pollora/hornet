<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

/**
 * The answer to a POST: a status, how many seconds the service asked to wait, and the start of its body (4 KB at most).
 */
final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public ?int $retryAfter = null,
        public string $body = '',
    ) {}
}
