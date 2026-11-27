<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Domain\Scan\PluginListingCache;

final class InMemoryPluginListingCache implements PluginListingCache
{
    /** @var array<string, array{at: int, listing: PluginListing}> */
    public array $stored = [];

    public function get(string $slug): ?array
    {
        return $this->stored[$slug] ?? null;
    }

    public function put(string $slug, int $at, PluginListing $listing): void
    {
        $this->stored[$slug] = ['at' => $at, 'listing' => $listing];
    }
}
