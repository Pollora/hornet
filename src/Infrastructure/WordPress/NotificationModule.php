<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use Pollora\Hornet\Application\Notification\NotificationService;

/**
 * Runs the audit on a schedule and tells the channels what is new; tries the waiting messages again.
 *
 * It rides on WP-Cron, which only runs when a page is visited: on a production site, turn it off with `DISABLE_WP_CRON` and
 * call `wp cron event run --due-now` from the system cron, or run `wp hornet notify run` yourself.
 *
 * @internal
 */
final readonly class NotificationModule implements Module
{
    public const string AUDIT_HOOK = 'pollora/hornet/scheduled_audit';

    public const string RETRY_HOOK = 'pollora/hornet/retry_notifications';

    /** Seconds before a waiting message is looked at again (the message itself has its own, longer, due time). */
    private const int RETRY_EVERY = 300;

    /**
     * @param  Closure(): NotificationService  $service  built when it is needed: a request that does not run the cron builds nothing
     * @param  string  $schedule  off, hourly, twicedaily or daily
     */
    public function __construct(
        private Closure $service,
        private string $schedule,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('init', $this->plan(...));
        $hooks->addAction(self::AUDIT_HOOK, $this->audit(...));
        $hooks->addAction(self::RETRY_HOOK, $this->retry(...));
    }

    /**
     * Puts the audit in the cron, once, and moves it when the schedule changes.
     */
    public function plan(): void
    {
        $scheduled = wp_get_schedule(self::AUDIT_HOOK);

        if ($this->schedule === 'off') {
            if ($scheduled !== false) {
                wp_clear_scheduled_hook(self::AUDIT_HOOK);
            }

            return;
        }

        if ($scheduled === $this->schedule) {
            return;
        }

        wp_clear_scheduled_hook(self::AUDIT_HOOK);
        // Five minutes from now: not in the middle of the request that installed it
        wp_schedule_event(time() + 300, $this->schedule, self::AUDIT_HOOK);
    }

    public function audit(): void
    {
        $service = ($this->service)();
        $service->run();
        $this->planRetry($service);
    }

    public function retry(): void
    {
        $service = ($this->service)();
        $service->retry();
        $this->planRetry($service);
    }

    private function planRetry(NotificationService $service): void
    {
        if ($service->queued() > 0 && wp_next_scheduled(self::RETRY_HOOK) === false) {
            wp_schedule_single_event(time() + self::RETRY_EVERY, self::RETRY_HOOK);
        }
    }
}
