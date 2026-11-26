<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\CoreChecksums;

/**
 * The checksums that api.wordpress.org publishes for each release of the core.
 *
 * Only the version and the locale leave the site: not its address, not a plugin, not a file name.
 *
 * @internal
 */
final readonly class WordPressCoreChecksums implements CoreChecksums
{
    private const string URL = 'https://api.wordpress.org/core/checksums/1.0/';

    /** The answer is about 300 KB: a larger one is not the list. */
    private const int MAX_BYTES = 4 * 1024 * 1024;

    public function __construct(private HttpClient $http) {}

    public function checksums(string $version, string $locale): ?array
    {
        $response = $this->http->get(Secret::of(self::URL.'?'.http_build_query(['version' => $version, 'locale' => $locale])), ['Accept' => 'application/json'], self::MAX_BYTES, 30);

        if ($response->status !== 200) {
            throw new TransportException(sprintf('api.wordpress.org answered HTTP %d.', $response->status));
        }

        $data = json_decode($response->body, true);

        if (! is_array($data) || ! array_key_exists('checksums', $data)) {
            throw new TransportException('api.wordpress.org sent an answer that is not the list of checksums.');
        }

        // An unknown version or locale is not an error: the API answers 200 with `false`
        if ($data['checksums'] === false) {
            return null;
        }

        if (! is_array($data['checksums']) || $data['checksums'] === []) {
            throw new TransportException('api.wordpress.org sent an empty list of checksums.');
        }

        $checksums = [];

        foreach ($data['checksums'] as $path => $md5) {
            if (is_string($md5)) {
                $checksums[(string) $path] = $md5;
            }
        }

        return $checksums;
    }
}
