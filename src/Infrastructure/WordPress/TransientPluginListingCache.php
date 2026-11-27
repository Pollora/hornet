<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Domain\Scan\PluginListingCache;

/**
 * What the directory said, in a transient per folder name (a site transient on a network: the files are shared). Stored as JSON.
 *
 * @internal
 */
final readonly class TransientPluginListingCache implements PluginListingCache
{
    /** As long as the longest an answer is trusted. */
    private const int KEEP = 604800;

    public function get(string $slug): ?array
    {
        $stored = get_site_transient($this->name($slug));
        $data = is_string($stored) ? json_decode($stored, true) : null;
        $listing = is_array($data) ? PluginListing::fromArray($data['listing'] ?? null) : null;

        return is_array($data) && is_int($data['at'] ?? null) && $listing instanceof PluginListing ? ['at' => $data['at'], 'listing' => $listing] : null;
    }

    public function put(string $slug, int $at, PluginListing $listing): void
    {
        set_site_transient($this->name($slug), json_encode(['at' => $at, 'listing' => $listing->toArray()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), self::KEEP);
    }

    private function name(string $slug): string
    {
        return 'hornet_wporg_'.md5($slug);
    }
}
