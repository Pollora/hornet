<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminBackend;

/**
 * A site seen from the administration: the functions that the factories of the auditor and of the scanner ask, and an
 * {@see AdminBackend} on a made-up environment. The cron, the journal and the scan state are kept for the test to read.
 */
final class AdminSite
{
    public FakeSite $site;

    public InMemoryScanStateStore $state;

    public InMemoryJournal $journal;

    public JournalRecorder $recorder;

    public FakeWpdb $db;

    /** @var list<array{int, string}> the events asked of the cron */
    public array $scheduled = [];

    public int $spawned = 0;

    /** When the scan that was asked for is due, false when none is in the cron. */
    public int|false $nextScan = false;

    public function __construct()
    {
        self::stubScanner();
        $this->site = FakeSite::install();
        $this->state = new InMemoryScanStateStore;
        $this->journal = new InMemoryJournal;
        $this->recorder = new JournalRecorder($this->journal, new FakeRequestContext, new FakeClock);
        $this->db = $GLOBALS['wpdb'] = new FakeWpdb;

        Functions\when('wp_next_scheduled')->alias(fn (): int|false => $this->nextScan);
        Functions\when('wp_schedule_single_event')->alias(function (int $at, string $hook): bool {
            $this->scheduled[] = [$at, $hook];

            return true;
        });
        Functions\when('spawn_cron')->alias(function (): void {
            $this->spawned++;
        });
        Functions\when('wp_get_current_user')->justReturn(FakeWpUser::named(7, 'olivier'));
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('site_url')->alias(static fn (string $path = ''): string => 'https://example.com/'.$path);
        Functions\when('get_bloginfo')->justReturn('Example');
    }

    /**
     * What building a scanner asks of WordPress.
     */
    public static function stubScanner(): void
    {
        Functions\when('wp_get_wp_version')->justReturn('7.1.5');
        Functions\when('get_theme_root')->justReturn(WP_CONTENT_DIR.'/themes');
        Functions\when('wp_get_upload_dir')->justReturn(['basedir' => WP_CONTENT_DIR.'/uploads', 'baseurl' => 'https://example.com/wp-content/uploads/']);
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://example.com'.$path);
        Functions\when('content_url')->justReturn('https://example.com/wp-content/');
        Functions\when('untrailingslashit')->alias(static fn (string $text): string => rtrim($text, '/'));
        Functions\when('wp_get_environment_type')->justReturn('production');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function backend(array $config = [], ?Environment $environment = null, bool $recording = true): AdminBackend
    {
        $job = new ScanJob(static fn (): null => null, new ArrayLock, $this->state, new SystemClock, static fn (): array => []);

        return new AdminBackend(Settings::fromArray($config), $environment ?? self::weakEnvironment(), $job, $recording ? $this->recorder : null);
    }

    /**
     * A site with something to say: no salts (high), no https.
     */
    public static function weakEnvironment(): FakeEnvironment
    {
        return new FakeEnvironment(https: false);
    }
}
