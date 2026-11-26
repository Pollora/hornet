<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Secret;

/**
 * The WordPress HTTP API.
 *
 * It uses the "safe" variant, which refuses a private or local address, unless the owner allowed one for this channel.
 * It never follows a redirect (an alert must not be sent somewhere the owner did not choose), and an error never repeats the URL.
 *
 * @internal
 */
final class WpHttpClient implements HttpClient
{
    private const int TIMEOUT = 10;

    /**
     * @param  array<mixed>  $response
     * @return array<string, string> names in lower case; a header sent twice is joined with a comma
     */
    private static function headers(array $response): array
    {
        $headers = [];

        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[strtolower((string) $name)] = implode(', ', array_map(strval(...), (array) $value));
        }

        return $headers;
    }

    public function post(Secret $url, array $headers, string $body, bool $allowPrivate = false): HttpResponse
    {
        $args = ['timeout' => self::TIMEOUT, 'redirection' => 0, 'headers' => $headers, 'body' => $body, 'user-agent' => 'Hornet (WordPress security)'];
        $response = $allowPrivate ? wp_remote_post($url->reveal(), $args) : wp_safe_remote_post($url->reveal(), $args);

        if (is_wp_error($response)) {
            // The code says what failed (http_request_failed, a refused address…); the message often contains the URL
            throw new TransportException('The request failed ('.$response->get_error_code().').');
        }

        $retryAfter = wp_remote_retrieve_header($response, 'retry-after');

        return new HttpResponse((int) wp_remote_retrieve_response_code($response), is_string($retryAfter) && ctype_digit($retryAfter) ? (int) $retryAfter : null, substr(wp_remote_retrieve_body($response), 0, 4096));
    }

    public function get(Secret $url, array $headers, int $maxBytes, int $timeout = self::TIMEOUT, bool $allowPrivate = false): HttpResponse
    {
        $args = ['timeout' => $timeout, 'redirection' => 0, 'headers' => $headers, 'limit_response_size' => $maxBytes, 'user-agent' => 'Hornet (WordPress security)'];
        $response = $allowPrivate ? wp_remote_get($url->reveal(), $args) : wp_safe_remote_get($url->reveal(), $args);

        if (is_wp_error($response)) {
            throw new TransportException('The request failed ('.$response->get_error_code().').');
        }

        return new HttpResponse((int) wp_remote_retrieve_response_code($response), null, substr(wp_remote_retrieve_body($response), 0, $maxBytes), self::headers($response));
    }
}
