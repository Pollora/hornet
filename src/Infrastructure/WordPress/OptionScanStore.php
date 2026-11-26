<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use JsonException;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanStore;

/**
 * The last scan, in one option that WordPress does not load on every page. On a network it is kept for the network:
 * the files are shared by every site, so one scan tells the same story to all of them.
 *
 * It is stored as JSON, never as a PHP-serialized object.
 *
 * @internal
 */
final class OptionScanStore implements ScanStore
{
    public const string OPTION = 'hornet_scan';

    public function last(): ?ScanSnapshot
    {
        $stored = is_multisite() ? get_network_option(null, self::OPTION, '') : get_option(self::OPTION, '');

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return ScanSnapshot::fromArray(json_decode($stored, true, 16, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }
    }

    public function save(ScanSnapshot $snapshot): void
    {
        $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (is_multisite()) {
            update_network_option(null, self::OPTION, $json);

            return;
        }

        // Created without autoload: the scan is read by the audit only, and it may be large
        add_option(self::OPTION, $json, '', false);
        update_option(self::OPTION, $json);
    }
}
