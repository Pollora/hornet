<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * On a network the files are shared and the result of the scan is kept for all of it, so it is the main site that scans. A scan
 * run as another site would read that site's database, uploads and address, and write what it saw over what the network knows.
 *
 * @internal
 */
final class NetworkScan
{
    /**
     * Why a scan must not run on the site being looked at; null when it may (a single site, or the main site of a network).
     */
    public static function refusal(): ?string
    {
        if (! is_multisite() || is_main_site()) {
            return null;
        }

        return sprintf('On a network the files are shared and the main site scans them: nothing was scanned. Run it again with --url=%s', get_home_url(get_main_site_id()));
    }
}
