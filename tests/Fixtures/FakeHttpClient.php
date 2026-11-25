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

    public function post(Secret $url, array $headers, string $body, bool $allowPrivate = false): HttpResponse
    {
        $this->posts[] = ['url' => $url->reveal(), 'headers' => $headers, 'body' => $body, 'private' => $allowPrivate];
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
