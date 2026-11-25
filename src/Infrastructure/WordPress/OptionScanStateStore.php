<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use JsonException;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Domain\Scan\ScanStateStore;

/**
 * Where a scan is, in an option that is not loaded on every page (network-wide on a multisite: the scan is shared). Stored as JSON.
 */
final readonly class OptionScanStateStore implements ScanStateStore
{
    public const string OPTION = 'hornet_scan_state';

    public function get(): ScanState
    {
        $stored = is_multisite() ? get_network_option(null, self::OPTION, '') : get_option(self::OPTION, '');

        if (! is_string($stored) || $stored === '') {
            return ScanState::idle();
        }

        try {
            return ScanState::fromArray(json_decode($stored, true, 4, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return ScanState::idle();
        }
    }

    public function save(ScanState $state): void
    {
        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR);

        if (is_multisite()) {
            update_network_option(null, self::OPTION, $json);

            return;
        }

        add_option(self::OPTION, $json, '', false);
        update_option(self::OPTION, $json);
    }
}
