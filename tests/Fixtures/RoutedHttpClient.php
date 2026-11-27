<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Secret;

/**
 * A site that answers by address. What it does not know is a 404, or the home page for everything when it is a soft-404 site.
 */
final class RoutedHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<string, HttpResponse|TransportException>  $routes
     * @param  HttpResponse|null  $fallback  what every other address gets (a soft 404: 200 with the home page)
     */
    public function __construct(
        public array $routes = [],
        public ?HttpResponse $fallback = null,
        public bool $down = false,
        public ?\Closure $onAsk = null,
    ) {}

    public function post(Secret $url, array $headers, string $body, bool $allowPrivate = false, int $maxBytes = 4096, int $timeout = 10): HttpResponse
    {
        throw new \LogicException('The probes never post.');
    }

    public function get(Secret $url, array $headers, int $maxBytes, int $timeout = 10, bool $allowPrivate = false): HttpResponse
    {
        $address = $url->reveal();
        $this->asked[] = $address;

        if ($this->onAsk instanceof \Closure) {
            ($this->onAsk)($address);
        }

        if ($this->down) {
            throw new TransportException('The request failed (http_request_failed).');
        }

        $answer = $this->routes[$address] ?? $this->fallback ?? new HttpResponse(404, null, 'Not found');

        return $answer instanceof TransportException ? throw $answer : new HttpResponse($answer->status, null, substr($answer->body, 0, $maxBytes));
    }
}
