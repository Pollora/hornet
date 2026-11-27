<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * The database, asked once in a while: which records concern a release is kept six hours, a record a week (it is asked again as
 * soon as its date of change differs). It is what lets a scan that ran out of time go on where it stopped. A failure is not kept.
 *
 * @internal
 */
final class CachedAdvisoryDatabase implements AdvisoryDatabase
{
    private int $fetched = 0;

    public function __construct(
        private readonly AdvisoryDatabase $inner,
        private readonly AdvisoryCache $cache,
        private readonly int $matchesFor = 21600,
        private readonly int $recordFor = 604800,
    ) {}

    public function affecting(array $packages): array
    {
        $answers = [];
        $ask = [];

        foreach ($packages as $index => $package) {
            $kept = $this->cache->get(self::matchKey($package));

            if ($kept === null) {
                $ask[$index] = $package;
            } else {
                $answers[$index] = array_filter($kept, is_string(...));
            }
        }

        if ($ask !== []) {
            $found = $this->inner->affecting(array_values($ask));

            foreach (array_keys($ask) as $position => $index) {
                $answers[$index] = $found[$position];
                $this->cache->put(self::matchKey($ask[$index]), $found[$position], $this->matchesFor);
                $this->fetched++;
            }
        }

        ksort($answers);

        return array_values($answers);
    }

    public function advisory(string $id, string $modified): Advisory
    {
        $key = 'record:'.$id.':'.$modified;
        $kept = Advisory::fromArray($this->cache->get($key));

        if ($kept instanceof Advisory) {
            return $kept;
        }

        $advisory = $this->inner->advisory($id, $modified);
        $this->fetched++;
        $this->cache->put($key, $advisory->toArray(), $this->recordFor);

        return $advisory;
    }

    /**
     * How many answers came from the database itself since the start: a run that got none has not moved forward.
     */
    public function fetched(): int
    {
        return $this->fetched;
    }

    private static function matchKey(ComposerPackage $package): string
    {
        return 'match:'.$package->name.':'.$package->version;
    }
}
