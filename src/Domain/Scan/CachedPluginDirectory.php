<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Clock;

/**
 * The directory, asked once in a while. It is also what lets a scan that ran out of time go on where it stopped: what was
 * answered is not asked again.
 *
 * A failure is never kept, and an old answer never stands in for one: "could not ask" is said as such. A closed plugin is
 * rarely reopened, so that answer is trusted longer than "still there".
 *
 * @internal
 */
final class CachedPluginDirectory implements PluginDirectory
{
    private int $fetched = 0;

    /**
     * @param  int  $freshFor  seconds a "listed" or "absent" answer is used
     * @param  int  $closedFor  seconds a "closed" answer is used
     */
    public function __construct(
        private readonly PluginDirectory $inner,
        private readonly PluginListingCache $cache,
        private readonly Clock $clock,
        private readonly int $freshFor = 86400,
        private readonly int $closedFor = 604800,
    ) {}

    public function of(string $slug): PluginListing
    {
        $now = $this->clock->now()->getTimestamp();
        $kept = $this->cache->get($slug);

        if ($kept !== null && $now - $kept['at'] < ($kept['listing']->status === PluginListingStatus::Closed ? $this->closedFor : $this->freshFor)) {
            return $kept['listing'];
        }

        $listing = $this->inner->of($slug);
        $this->fetched++;
        $this->cache->put($slug, $now, $listing);

        return $listing;
    }

    /**
     * How many answers came from the directory itself since the start: a run that got none has not moved forward.
     */
    public function fetched(): int
    {
        return $this->fetched;
    }
}
