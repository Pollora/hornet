<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Secret;

/**
 * Records what was posted and answers with what the test set.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, private: bool}> */
    public array $posts = [];

    /** @var list<HttpResponse|TransportException> */
    public array $answers = [];

    /** @var list<array{maxBytes: int, timeout: int}> how much each POST asked to read, and for how long it waited */
    public array $limits = [];

    public function post(Secret $url, array $headers, string $body, bool $allowPrivate = false, int $maxBytes = 4096, int $timeout = 10): HttpResponse
    {
        $this->limits[] = ['maxBytes' => $maxBytes, 'timeout' => $timeout];
        $this->posts[] = ['url' => $url->reveal(), 'headers' => $headers, 'body' => $body, 'private' => $allowPrivate];
        $answer = array_shift($this->answers) ?? new HttpResponse(200);

        if ($answer instanceof TransportException) {
            throw $answer;
        }

        return $answer;
    }

    /** @var list<array{url: string, headers: array<string, string>, maxBytes: int, timeout: int, private?: bool}> */
    public array $gets = [];

    public function get(Secret $url, array $headers, int $maxBytes, int $timeout = 10, bool $allowPrivate = false): HttpResponse
    {
        $this->gets[] = ['url' => $url->reveal(), 'headers' => $headers, 'maxBytes' => $maxBytes, 'timeout' => $timeout, 'private' => $allowPrivate];
        $answer = array_shift($this->answers) ?? new HttpResponse(200);

        if ($answer instanceof TransportException) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        $last = $this->posts[array_key_last($this->posts)] ?? throw new \LogicException('Nothing was posted.');

        /** @var array<string, mixed> */
        return json_decode($last['body'], true, 512, JSON_THROW_ON_ERROR);
    }
}
