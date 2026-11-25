<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification\Channels;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\Channel;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\Secret;

/**
 * A channel that is one POST: the subclass says what to send, the answer is read the same way for all.
 */
abstract class HttpChannel implements Channel
{
    public function __construct(
        private readonly string $name,
        private readonly Severity $minSeverity,
        private readonly HttpClient $http,
        private readonly Secret $url,
        private readonly bool $allowPrivate = false,
    ) {}

    final public function name(): string
    {
        return $this->name;
    }

    final public function minSeverity(): Severity
    {
        return $this->minSeverity;
    }

    final public function send(Notification $notification): Delivery
    {
        [$headers, $body] = $this->request($notification);

        try {
            return $this->interpret($this->http->post($this->url, $headers, $body, $this->allowPrivate));
        } catch (TransportException $transportException) {
            // A network problem passes: try again
            return Delivery::retry($transportException->getMessage());
        }
    }

    /**
     * The headers and the body to post.
     *
     * @return array{array<string, string>, string}
     */
    abstract protected function request(Notification $notification): array;

    /**
     * What an answer means. By default a 2xx is a success, a 429 or 5xx can pass, any other 4xx cannot.
     */
    protected function interpret(HttpResponse $response): Delivery
    {
        return Delivery::fromStatus($response->status, $response->retryAfter);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    final protected function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
