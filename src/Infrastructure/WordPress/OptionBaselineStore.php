<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use JsonException;
use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\BaselineStore;

/**
 * What was first seen of the plugins WordPress.org does not know, in one option that is not loaded on every page
 * (network-wide on a multisite: the files are shared). Stored as JSON.
 */
final class OptionBaselineStore implements BaselineStore
{
    public const string OPTION = 'hornet_baseline';

    public function load(): array
    {
        $stored = is_multisite() ? get_network_option(null, self::OPTION, '') : get_option(self::OPTION, '');

        if (! is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $data = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $baselines = [];

        foreach (is_array($data) ? $data : [] as $slug => $stored) {
            $baseline = Baseline::fromArray($stored);

            if ($baseline instanceof Baseline) {
                $baselines[(string) $slug] = $baseline;
            }
        }

        return $baselines;
    }

    public function save(array $baselines): void
    {
        $json = json_encode(array_map(static fn (Baseline $baseline): array => $baseline->toArray(), $baselines), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (is_multisite()) {
            update_network_option(null, self::OPTION, $json);

            return;
        }

        add_option(self::OPTION, $json, '', false);
        update_option(self::OPTION, $json);
    }
}
