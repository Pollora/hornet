<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Pollora\CorePatches;
use Pollora\Hornet\Infrastructure\Scan\NativeFileSystem;

/**
 * Builds the scanner for the running WordPress. It is built when `wp hornet scan` runs: WordPress, and its files, are known by then.
 */
final class ScannerFactory
{
    /**
     * @return Scanner|null null when the install cannot be read from here
     */
    public static function forWordPress(Settings $settings): ?Scanner
    {
        $files = NativeFileSystem::at(ABSPATH);

        if (! $files instanceof NativeFileSystem) {
            return null;
        }

        // What `wp core verify-checksums` reads too: a core downloaded in another language is compared with that language
        $locale = $GLOBALS['wp_local_package'] ?? null;

        return new Scanner(
            [new CoreIntegrityTask(new WordPressCoreChecksums(new WpHttpClient), $files, wp_get_wp_version(), is_string($locale) && $locale !== '' ? $locale : 'en_US', array_values(array_unique([...$settings->scanner->allowModified, ...CorePatches::files()])))],
            new OptionScanStore,
            new SystemClock,
        );
    }
}
