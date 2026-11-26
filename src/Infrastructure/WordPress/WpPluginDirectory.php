<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\PluginDirectory;
use Pollora\Hornet\Domain\Scan\PluginListing;

/**
 * The plugin information of api.wordpress.org, with every long field turned off.
 *
 * A closed plugin is answered with HTTP 404 and `{"error":"closed","closed":true,"closed_date":…,"reason":…}` (the date and
 * the reason can be `false`: the reason is held back for sixty days), an unknown folder name with HTTP 404 and
 * `{"error":"Plugin not found."}`. Any other answer is "could not ask": a 404 that is neither of these must not read as
 * "not on WordPress.org", which would pass.
 *
 * @internal
 */
final readonly class WpPluginDirectory implements PluginDirectory
{
    private const string URL = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information';

    private const array UNWANTED = ['sections', 'description', 'short_description', 'versions', 'reviews', 'ratings', 'banners', 'icons', 'screenshots', 'contributors', 'tags'];

    private const int MAX_BYTES = 512 * 1024;

    private const string NOT_FOUND = 'Plugin not found.';

    public function __construct(private HttpClient $http) {}

    public function of(string $slug): PluginListing
    {
        // A folder name that is not a plain one is not a slug of the directory, and is not put in an address
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $slug) !== 1) {
            return PluginListing::absent();
        }

        $fields = implode('', array_map(static fn (string $field): string => '&request%5Bfields%5D%5B'.$field.'%5D=0', self::UNWANTED));
        $response = $this->http->get(Secret::of(self::URL.'&request%5Bslug%5D='.rawurlencode($slug).$fields), ['Accept' => 'application/json'], self::MAX_BYTES);
        $data = json_decode($response->body, true);

        if (is_array($data) && in_array($response->status, [200, 404], true)) {
            if (($data['closed'] ?? false) === true && is_string($data['name'] ?? null)) {
                $date = $data['closed_date'] ?? null;

                return PluginListing::closed($data['name'], is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 ? $date : null, is_string($data['reason'] ?? null) ? $data['reason'] : null);
            }

            if ($response->status === 404 && ($data['error'] ?? null) === self::NOT_FOUND) {
                return PluginListing::absent();
            }

            if ($response->status === 200 && ! isset($data['error']) && is_string($data['name'] ?? null) && is_string($data['slug'] ?? null) && strcasecmp($data['slug'], $slug) === 0) {
                $version = $data['version'] ?? null;

                return PluginListing::listed($data['name'], is_string($version) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $version) === 1 ? $version : null, self::date($data['last_updated'] ?? null));
            }
        }

        throw new TransportException(sprintf('api.wordpress.org sent HTTP %d and nothing that can be read.', $response->status));
    }

    /**
     * `2026-08-18 11:42pm GMT`: the day is what counts.
     */
    private static function date(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})(?: |$)/', $value, $matches) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1], new DateTimeZone('UTC'));

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $matches[1] ? $date->getTimestamp() : null;
    }
}
