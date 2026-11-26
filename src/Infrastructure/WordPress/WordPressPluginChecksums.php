<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\CoreIntegrity;
use Pollora\Hornet\Domain\Scan\PluginChecksums;

/**
 * The checksums that downloads.wordpress.org publishes for each release of each plugin of the directory.
 *
 * Only the slug and the version leave the site. A slug or a version that is not a plain name is never put in an address.
 *
 * @internal
 */
final readonly class WordPressPluginChecksums implements PluginChecksums
{
    private const string URL = 'https://downloads.wordpress.org/plugin-checksums/';

    /** The list of a large plugin is a few MB: a larger answer is not the list. */
    private const int MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private HttpClient $http) {}

    public function checksums(string $slug, string $version): ?array
    {
        // A name that is not a plain one (a path, a query) has no list: it is not asked for
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $slug) !== 1 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $version) !== 1) {
            return null;
        }

        $response = $this->http->get(Secret::of(self::URL.rawurlencode($slug).'/'.rawurlencode($version).'.json'), ['Accept' => 'application/json'], self::MAX_BYTES, 30);

        // No list for this version: a plugin that is not in the directory, or a version it never had
        if ($response->status === 404) {
            return null;
        }

        if ($response->status !== 200) {
            throw new TransportException(sprintf('downloads.wordpress.org answered HTTP %d.', $response->status));
        }

        $data = json_decode($response->body, true);

        if (! is_array($data) || ! is_array($data['files'] ?? null) || $data['files'] === []) {
            throw new TransportException('downloads.wordpress.org sent an answer that is not a list of checksums.');
        }

        $files = [];

        foreach ($data['files'] as $path => $hashes) {
            $accepted = is_array($hashes) ? ($hashes['sha256'] ?? null) : null;
            $accepted = is_string($accepted) ? [$accepted] : $accepted;

            if (is_array($accepted) && CoreIntegrity::isSafe((string) $path)) {
                $valid = array_values(array_filter($accepted, static fn (mixed $hash): bool => is_string($hash) && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1));

                if ($valid !== []) {
                    $files[(string) $path] = $valid;
                }
            }
        }

        // A list that holds no usable hash would call every file unexpected
        if ($files === []) {
            throw new TransportException('downloads.wordpress.org sent a list without usable checksums.');
        }

        return $files;
    }
}
