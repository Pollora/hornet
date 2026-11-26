<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WpCli;

use Closure;
use InvalidArgumentException;
use Pollora\Hornet\Application\Notification\ChannelInfo;
use Pollora\Hornet\Application\Notification\ChannelOutcome;
use Pollora\Hornet\Application\Notification\ChannelStatus;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Domain\Audit\Report;

/**
 * Tell the channels about the site's security, and check that they work.
 *
 * @internal
 */
final readonly class NotifyCommand
{
    /**
     * @param  Closure(): ?NotificationService  $service  null when no channel is configured; built when a command runs
     */
    public function __construct(
        private Closure $service,
        private Output $output,
    ) {}

    /**
     * Audit the site now and tell every channel what is new, as the schedule does.
     *
     * Use it from the system cron when WP-Cron is off: `wp hornet notify run`.
     *
     * ## EXAMPLES
     *
     *     wp hornet notify run
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function run(array $args = [], array $assoc = []): void
    {
        $service = $this->service();

        if (! $service instanceof NotificationService) {
            return;
        }

        $run = $service->run();

        if (! $run->report instanceof Report) {
            $this->output->warning('Another run is in progress: nothing was done.');

            return;
        }

        $this->report($run->outcomes);
    }

    /**
     * Send a test message to one channel, whatever its severity setting and cooldown.
     *
     * ## OPTIONS
     *
     * <channel>
     * : The name of the channel, as in the settings (see `wp hornet notify status`).
     *
     * ## EXAMPLES
     *
     *     wp hornet notify test ops-slack
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function test(array $args = [], array $assoc = []): void
    {
        $name = $args[0] ?? '';

        if ($name === '') {
            $this->output->error('Usage: wp hornet notify test <channel>');

            return;
        }

        $service = $this->service();

        if (! $service instanceof NotificationService) {
            return;
        }

        $outcome = $service->test($name);

        if ($outcome->status === ChannelStatus::Failed) {
            $this->output->error(sprintf('The test message to "%s" failed: %s', $name, $outcome->reason));

            return;
        }

        $this->output->success(sprintf('The test message reached "%s".', $name));
    }

    /**
     * List the channels and how many messages wait for another attempt.
     *
     * Addresses and secrets are never shown.
     *
     * ## EXAMPLES
     *
     *     wp hornet notify status
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function status(array $args = [], array $assoc = []): void
    {
        $service = $this->service();

        if (! $service instanceof NotificationService) {
            return;
        }

        $this->output->items(
            array_map(static fn (ChannelInfo $channel): array => ['channel' => $channel->name, 'type' => $channel->type, 'min_severity' => $channel->minSeverity->value], $service->channels()),
            ['channel', 'type', 'min_severity'],
            'table',
        );
        $this->output->line(sprintf('%d message(s) waiting for another attempt.', $service->queued()));
    }

    /**
     * Try the waiting messages again now.
     *
     * ## EXAMPLES
     *
     *     wp hornet notify retry
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function retry(array $args = [], array $assoc = []): void
    {
        $service = $this->service();

        if (! $service instanceof NotificationService) {
            return;
        }

        $outcomes = $service->retry();

        if ($outcomes === []) {
            $this->output->success('Nothing was due.');

            return;
        }

        $this->report($outcomes);
    }

    /**
     * The service, or null after saying why there is none (wrong settings, no channel): the command stops there.
     */
    private function service(): ?NotificationService
    {
        try {
            $service = ($this->service)();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->output->error($invalidArgumentException->getMessage());

            return null;
        }

        if (! $service instanceof NotificationService) {
            $this->output->error('No notification channel is configured: add some under "notifications" in the settings.');

            return null;
        }

        return $service;
    }

    /**
     * @param  list<ChannelOutcome>  $outcomes
     */
    private function report(array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $this->output->line(sprintf('%-20s %s%s%s', $outcome->channel, $outcome->status->value, $outcome->alerts > 0 ? sprintf(' (%d alert%s)', $outcome->alerts, $outcome->alerts === 1 ? '' : 's') : '', $outcome->reason !== '' ? ': '.$outcome->reason : ''));
        }

        foreach ($outcomes as $outcome) {
            if ($outcome->status === ChannelStatus::Failed) {
                $this->output->error('At least one channel failed.');

                return;
            }
        }

        $this->output->success('Done.');
    }
}
