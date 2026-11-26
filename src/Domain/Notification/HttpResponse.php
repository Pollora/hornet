<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

/**
 * The answer to a POST: a status, how many seconds the service asked to wait, and the start of its body (4 KB at most).
 *
 * @internal
 */
final readonly class HttpResponse
{
    /**
     * @param  array<string, string>  $headers  the headers of the answer, their names in lower case; empty when the caller does not need them
     */
    public function __construct(
        public int $status,
        public ?int $retryAfter = null,
        public string $body = '',
        public array $headers = [],
    ) {}
}
