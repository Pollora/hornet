<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\SuspiciousFilesTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\FileInspector;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Scan\NativeFileSystem;
use Pollora\Hornet\Infrastructure\WordPress\OptionRunLock;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStateStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use Pollora\Hornet\Infrastructure\WordPress\ScannerFactory;

function contentWalk(array $ignored = []): SuspiciousFilesTask
{
    $content = NativeFileSystem::at(WP_CONTENT_DIR);
    assert($content instanceof NativeFileSystem);
    $uploads = realpath(wp_get_upload_dir()['basedir']);

    return new SuspiciousFilesTask($content, new FileInspector($content, $uploads === false ? '' : substr($uploads, strlen((string) realpath(WP_CONTENT_DIR)) + 1)), new SystemClock, $ignored);
}

afterEach(function (): void {
    delete_option(OptionScanStore::OPTION);
    delete_option('hornet_scan_lock');
    wp_clear_scheduled_hook(ScanModule::SCAN_HOOK);
});

it('finds nothing suspicious in a fresh WordPress', function (): void {
    expect(contentWalk()->run(null, false)->finding->outcome)->toBe(Outcome::Pass);
});

it('finds what is planted in the real uploads, a theme and a mu-plugin, and deletes nothing', function (): void {
    $uploads = wp_get_upload_dir()['basedir'];
    wp_mkdir_p($uploads.'/2026/11');
    $planted = [
        $uploads.'/2026/11/hornet-it-logo.php' => '<?php echo 1;',
        $uploads.'/2026/11/hornet-it-pic.php.jpg' => 'x',
        $uploads.'/.htaccess' => "AddHandler application/x-httpd-php .jpg\n",
        WP_CONTENT_DIR.'/themes/hornet-it-shell.php' => '<?php eval($_POST["x"]);',
    ];

    foreach ($planted as $path => $contents) {
        file_put_contents($path, $contents);
    }

    try {
        $finding = contentWalk()->run(null, false)->finding;
        $stillThere = array_filter(array_keys($planted), file_exists(...));
    } finally {
        foreach (array_keys($planted) as $path) {
            @unlink($path);
        }
    }

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::High)
        ->and($finding->summary)->toBe('4 files in the content folder would not be left unsaid.')
        ->and(count($stillThere))->toBe(4)
        ->and(implode("\n", $finding->details))->toContain('uploads/2026/11/hornet-it-logo.php')->toContain('uploads/.htaccess')->toContain('themes/hornet-it-shell.php');
});

it('leaves alone what the owner says to', function (): void {
    $file = WP_CONTENT_DIR.'/themes/hornet-it-known.php';
    file_put_contents($file, '<?php eval($_POST["x"]);');

    try {
        $finding = contentWalk(['themes/hornet-it-known.php'])->run(null, false)->finding;
    } finally {
        unlink($file);
    }

    expect($finding->outcome)->toBe(Outcome::Pass);
});

it('builds the scanner of the site with the walk, and a budget for the cron', function (): void {
    $settings = Settings::fromArray(['scanner' => ['ignore_paths' => ['uploads/known']]]);

    expect(ScannerFactory::forWordPress($settings)?->tasks())->toBe(['core-integrity', 'plugin-integrity', 'theme-integrity', 'suspicious-files', 'exposed-files'])
        ->and(ScannerFactory::forWordPress($settings, true))->toBeInstanceOf(Scanner::class);
});

it('keeps a lock in its own option, takes over a dead one, and lets go', function (): void {
    $lock = new OptionRunLock('hornet_scan_lock');

    expect($lock->acquire())->toBeTrue()->and($lock->acquire())->toBeFalse()
        ->and(get_option('hornet_notify_lock'))->toBeFalse();

    $lock->release();

    expect($lock->acquire())->toBeTrue();

    update_option('hornet_scan_lock', time() - 3600);

    expect($lock->acquire())->toBeTrue();
});

it('puts the scan in the real cron, moves it, and takes it out', function (): void {
    $module = fn (string $schedule): ScanModule => new ScanModule(new ScanJob(static fn (): ?Scanner => null, new OptionRunLock('hornet_scan_lock'), new OptionScanStateStore, new SystemClock, static fn (): array => []), $schedule);

    $module('daily')->plan();
    $daily = wp_get_schedule(ScanModule::SCAN_HOOK);
    $module('hourly')->plan();
    $hourly = wp_get_schedule(ScanModule::SCAN_HOOK);
    $module('off')->plan();

    expect($daily)->toBe('daily')->and($hourly)->toBe('hourly')->and(wp_next_scheduled(ScanModule::SCAN_HOOK))->toBeFalse();
});
