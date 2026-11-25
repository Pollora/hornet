<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Application\Scan\ScanJob;

/**
 * Runs the scan when it is time: on a schedule, when the owner asked for one (`scanner.schedule`; off by default), and when the
 * administration screen says "scan now".
 *
 * It rides on WP-Cron, which only runs when a page is visited and has the time limit of a web request: the walk of the content
 * folder stops on a budget and goes on a minute later (that run does only the walk), but the plugin scan is not cut in pieces.
 * On a large site, run `wp hornet scan` from the system cron (with `DISABLE_WP_CRON`). Two runs never overlap: the second steps aside.
 * The result is read by the audit, which tells the channels what is new at its own turn.
 */
final readonly class ScanModule implements Module
{
    public const string SCAN_HOOK = 'pollora/hornet/scheduled_scan';

    public const string CONTINUE_HOOK = 'pollora/hornet/continue_scan';

    /** The scan that someone asked for from the administration screen, once. */
    public const string NOW_HOOK = 'pollora/hornet/scan_now';

    /** Seconds before a walk that did not finish goes on. */
    private const int CONTINUE_AFTER = 60;

    /**
     * @param  string  $schedule  off, hourly, twicedaily or daily
     */
    public function __construct(
        private ScanJob $job,
        private string $schedule,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('init', $this->plan(...));
        $hooks->addAction(self::SCAN_HOOK, fn () => $this->scan());
        $hooks->addAction(self::NOW_HOOK, fn () => $this->scan());
        $hooks->addAction(self::CONTINUE_HOOK, $this->continue(...));
    }

    public function plan(): void
    {
        $scheduled = wp_get_schedule(self::SCAN_HOOK);

        if ($this->schedule === 'off') {
            if ($scheduled !== false) {
                wp_clear_scheduled_hook(self::SCAN_HOOK);
            }

            return;
        }

        if ($scheduled === $this->schedule) {
            return;
        }

        wp_clear_scheduled_hook(self::SCAN_HOOK);
        // Ten minutes from now: not in the middle of the request that installed it
        wp_schedule_event(time() + 600, $this->schedule, self::SCAN_HOOK);
    }

    /**
     * Goes on with what was left: only the tasks that stopped on their budget run again.
     */
    public function continue(): void
    {
        $unfinished = $this->job->unfinished();

        if ($unfinished !== []) {
            $this->scan($unfinished);
        }
    }

    /**
     * @param  list<string>|null  $only  the tasks to run; all of them when null
     */
    public function scan(?array $only = null): void
    {
        if ($this->job->run($only) && wp_next_scheduled(self::CONTINUE_HOOK) === false) {
            wp_schedule_single_event(time() + self::CONTINUE_AFTER, self::CONTINUE_HOOK);
        }
    }
}
