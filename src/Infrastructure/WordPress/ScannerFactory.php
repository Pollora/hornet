<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\PluginIntegrityTask;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\SuspiciousFilesTask;
use Pollora\Hornet\Domain\Scan\FileInspector;
use Pollora\Hornet\Domain\Scan\PluginIntegrity;
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
     * @param  bool  $cron  a run of WP-Cron: the walk of the content folder has a time budget, and the plugin scan a shorter one
     * @return Scanner|null null when the install cannot be read from here, or when every scan is disabled
     */
    public static function forWordPress(Settings $settings, bool $cron = false): ?Scanner
    {
        $files = NativeFileSystem::at(ABSPATH);

        if (! $files instanceof NativeFileSystem) {
            return null;
        }

        // What `wp core verify-checksums` reads too: a core downloaded in another language is compared with that language
        $locale = $GLOBALS['wp_local_package'] ?? null;

        $plugins = NativeFileSystem::at(WP_PLUGIN_DIR);
        $http = new WpHttpClient;
        $tasks = [new CoreIntegrityTask(new WordPressCoreChecksums($http), $files, wp_get_wp_version(), is_string($locale) && $locale !== '' ? $locale : 'en_US', array_values(array_unique([...$settings->scanner->allowModified, ...CorePatches::files()])))];

        // The plugins directory may be elsewhere than the WordPress root (Bedrock, Pollora)
        if ($plugins instanceof NativeFileSystem) {
            $tasks[] = new PluginIntegrityTask(new WpPluginInventory, new WordPressPluginChecksums($http), new PluginIntegrity($plugins), new OptionBaselineStore, new SystemClock, $cron ? max(5, intdiv(self::cronSeconds(), 2)) : 240);
        }

        $content = NativeFileSystem::at(WP_CONTENT_DIR);

        if ($content instanceof NativeFileSystem) {
            $tasks[] = new SuspiciousFilesTask($content, new FileInspector($content, self::uploads()), new SystemClock, $settings->scanner->ignorePaths, $cron ? max(5, intdiv(self::cronSeconds(), 4)) : null);
        }

        // A scan that is disabled does not run, and asks nothing of the network
        $tasks = array_values(array_filter($tasks, static fn (ScanTask $task): bool => ! in_array($task->id(), $settings->disabledChecks, true)));

        return $tasks === [] ? null : new Scanner($tasks, new OptionScanStore, new SystemClock, $settings->ignored);
    }

    /**
     * The uploads folder, relative to the content folder; empty when it lies elsewhere (then nothing is taken for the uploads).
     */
    private static function uploads(): string
    {
        $base = wp_get_upload_dir()['basedir'];
        $content = realpath(WP_CONTENT_DIR);
        $uploads = realpath($base);

        return $content !== false && $uploads !== false && str_starts_with($uploads, $content.'/') ? substr($uploads, strlen($content) + 1) : '';
    }

    /**
     * How long a run of WP-Cron may last in all: the time limit of the web request that runs it, less a margin, and never more than 45 seconds
     * (a web server cuts a request that lasts longer). The core, which asks one URL, is the margin; the plugins get half and the walk a quarter.
     */
    private static function cronSeconds(): int
    {
        $limit = (int) ini_get('max_execution_time');

        return max(10, min(45, $limit > 0 ? $limit - 8 : 45));
    }
}
