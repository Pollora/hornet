<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

use Pollora\Hornet\Domain\Exceptions\TransportException;

/**
 * The only door to the network: one POST, one answer.
 */
interface HttpClient
{
    /**
     * @param  array<string, string>  $headers
     * @param  bool  $allowPrivate  whether the address may be a private or local one (a self-hosted ntfy); refused otherwise
     *
     * @throws TransportException when nothing answered; its message never contains the URL
     */
    public function post(Secret $url, array $headers, string $body, bool $allowPrivate = false): HttpResponse;

    /**
     * @param  array<string, string>  $headers
     * @param  int  $maxBytes  how much of the answer is read: the rest is dropped
     * @param  int  $timeout  seconds to wait: a service that is slow must not slow the site down
     *
     * @throws TransportException when nothing answered; its message never contains the URL
     */
    public function get(Secret $url, array $headers, int $maxBytes, int $timeout = 10): HttpResponse;
}
