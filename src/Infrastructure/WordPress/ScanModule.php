<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use Pollora\Hornet\Application\Notification\RunLock;
use Pollora\Hornet\Application\Scan\Scanner;

/**
 * Runs the scan on a schedule, when the owner asked for one (`scanner.schedule`; off by default).
 *
 * It rides on WP-Cron, which only runs when a page is visited and has the time limit of a web request: the walk of the content
 * folder stops on a budget and goes on a minute later, but the plugin scan is not cut in pieces. On a large site, run `wp hornet scan`
 * from the system cron (with `DISABLE_WP_CRON`). Two runs never overlap: the second steps aside.
 * The result is read by the audit, which tells the channels what is new at its own turn.
 */
final readonly class ScanModule implements Module
{
    public const string SCAN_HOOK = 'pollora/hornet/scheduled_scan';

    public const string CONTINUE_HOOK = 'pollora/hornet/continue_scan';

    /** Seconds before a walk that did not finish goes on. */
    private const int CONTINUE_AFTER = 60;

    /**
     * @param  Closure(): ?Scanner  $scanner  built when it is needed: a request that does not run the cron builds nothing
     * @param  string  $schedule  hourly, twicedaily or daily
     */
    public function __construct(
        private Closure $scanner,
        private string $schedule,
        private RunLock $lock,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('init', $this->plan(...));
        $hooks->addAction(self::SCAN_HOOK, $this->scan(...));
        $hooks->addAction(self::CONTINUE_HOOK, $this->scan(...));
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

    public function scan(): void
    {
        if (! $this->lock->acquire()) {
            return;
        }

        try {
            $scanner = ($this->scanner)();

            if ($scanner instanceof Scanner && $scanner->run()->unfinished !== [] && wp_next_scheduled(self::CONTINUE_HOOK) === false) {
                wp_schedule_single_event(time() + self::CONTINUE_AFTER, self::CONTINUE_HOOK);
            }
        } finally {
            $this->lock->release();
        }
    }
}
