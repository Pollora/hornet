<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * @internal
 */
interface PluginListingCache
{
    /**
     * @return array{at: int, listing: PluginListing}|null
     */
    public function get(string $slug): ?array;

    public function put(string $slug, int $at, PluginListing $listing): void;
}
