<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\ExposedFilesTask;
use Pollora\Hornet\Application\Scan\KnownVulnerabilitiesTask;
use Pollora\Hornet\Application\Scan\PluginIntegrityTask;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\SecurityHeadersTask;
use Pollora\Hornet\Application\Scan\SuspiciousFilesTask;
use Pollora\Hornet\Domain\Audit\LaravelApplication;
use Pollora\Hornet\Domain\Scan\CachedVulnerabilityFeed;
use Pollora\Hornet\Domain\Scan\FileInspector;
use Pollora\Hornet\Domain\Scan\NoPublishedChecksums;
use Pollora\Hornet\Domain\Scan\PluginIntegrity;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Pollora\CorePatches;
use Pollora\Hornet\Infrastructure\Scan\NativeFileSystem;
use Pollora\Hornet\Infrastructure\Scan\UploadsProbeFiles;

/**
 * Builds the scanner for the running WordPress. It is built when `wp hornet scan` runs: WordPress, and its files, are known by then.
 */
final class ScannerFactory
{
    /**
     * @param  Closure(string): void|null  $progress  called with the id of each task before it runs
     * @param  bool  $cron  a run of WP-Cron: the walk of the content folder has a time budget, and the plugin scan a shorter one
     * @return Scanner|null null when the install cannot be read from here, or when every scan is disabled
     */
    public static function forWordPress(Settings $settings, bool $cron = false, ?Closure $progress = null): ?Scanner
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

        $themes = NativeFileSystem::at(get_theme_root());

        if ($themes instanceof NativeFileSystem) {
            $tasks[] = new PluginIntegrityTask(new WpThemeInventory($settings->scanner->ownThemes), new NoPublishedChecksums, new PluginIntegrity($themes), new OptionBaselineStore(OptionBaselineStore::THEMES), new SystemClock, $cron ? max(5, intdiv(self::cronSeconds(), 4)) : 240, PluginIntegrityTask::THEMES_ID, 'theme');
        }

        $content = NativeFileSystem::at(WP_CONTENT_DIR);

        if ($content instanceof NativeFileSystem) {
            $tasks[] = new SuspiciousFilesTask($content, new FileInspector($content, self::uploads()), new SystemClock, $settings->scanner->ignorePaths, $cron ? max(5, intdiv(self::cronSeconds(), 4)) : null);
        }

        if ($settings->scanner->probes) {
            $uploads = wp_get_upload_dir();
            $tasks[] = new ExposedFilesTask($http, untrailingslashit(home_url()), untrailingslashit(content_url()), untrailingslashit($uploads['baseurl']), $settings->scanner->probeUploads ? new UploadsProbeFiles($uploads['basedir']) : null, new SystemClock, $cron ? max(5, intdiv(self::cronSeconds(), 4)) : null, static fn (): string => bin2hex(random_bytes(8)), HostApplication::laravel() instanceof LaravelApplication);
            $tasks[] = new SecurityHeadersTask($http, home_url('/'), wp_get_environment_type() === 'production', $settings->headers);
        }

        // Opt-in: the name and version of every plugin and theme go to the feed
        if ($settings->vulnerabilities->enabled) {
            $feed = new CachedVulnerabilityFeed(new WpVulnerabilityFeed($http), new TransientVulnerabilityCache, new SystemClock);
            $tasks[] = new KnownVulnerabilitiesTask($feed, new WpPluginInventory, new WpThemeInventory($settings->scanner->ownThemes), wp_get_wp_version(), new SystemClock, $feed->staleAnswers(...), $cron ? max(5, intdiv(self::cronSeconds(), 2)) : null);
        }

        // A scan that is disabled does not run, and asks nothing of the network
        $tasks = array_values(array_filter($tasks, static fn (ScanTask $task): bool => ! in_array($task->id(), $settings->disabledChecks, true)));

        return $tasks === [] ? null : new Scanner($tasks, new OptionScanStore, new SystemClock, $settings->ignored, $progress);
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
