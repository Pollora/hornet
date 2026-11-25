<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Notification;

use Closure;
use DateInterval;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Channel;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\NotificationState;
use Pollora\Hornet\Domain\Notification\QueuedDelivery;
use Pollora\Hornet\Domain\Notification\RetryQueue;
use Throwable;

/**
 * Tells the channels what is new, once, without flooding them, and tries again when a service is down.
 *
 * - **Grouped**: a channel gets one message per run, with every alert it is due.
 * - **Told once**: the same alert is not repeated on a channel before the cooldown ends.
 * - **Calm**: a channel gets at most `rateLimit` messages an hour; the rest waits for the next run, nothing is dropped.
 * - **Persistent**: a message that fails for a reason that can pass (network, 429, 5xx) is queued with a growing delay;
 *   one that cannot succeed (a revoked token) is reported and not repeated.
 */
final readonly class Notifier
{
    /** @var array<string, Channel> */
    private array $channels;

    /**
     * @param  list<Channel>  $channels
     * @param  int  $cooldown  seconds before the same alert is told again on a channel
     * @param  int  $rateLimit  messages per channel per hour
     * @param  Closure(): string  $ids  makes the identifier of a message (random, and unique)
     */
    public function __construct(
        array $channels,
        private NotificationState $state,
        private RetryQueue $queue,
        private Clock $clock,
        private Backoff $backoff,
        private int $cooldown,
        private int $rateLimit,
        private Closure $ids,
    ) {
        $byName = [];

        foreach ($channels as $channel) {
            $byName[$channel->name()] = $channel;
        }

        $this->channels = $byName;
    }

    /**
     * @return list<string>
     */
    public function channelNames(): array
    {
        return array_keys($this->channels);
    }

    /**
     * @param  list<Alert>  $alerts
     * @return list<ChannelOutcome>
     */
    public function dispatch(array $alerts, string $siteName, string $siteUrl): array
    {
        $outcomes = [];

        foreach ($this->channels as $name => $channel) {
            $outcomes[] = $this->dispatchTo($name, $channel, $alerts, $siteName, $siteUrl);
        }

        return $outcomes;
    }

    /**
     * Sends a message to one channel to prove that it works: no severity filter, no cooldown, nothing remembered.
     */
    public function test(string $channelName, string $siteName, string $siteUrl): ChannelOutcome
    {
        $channel = $this->channels[$channelName] ?? null;

        if (! $channel instanceof Channel) {
            return new ChannelOutcome($channelName, ChannelStatus::Failed, 0, 'No channel is named "'.$channelName.'".');
        }

        $alert = new Alert('test', Severity::Info, 'This is a test message from Hornet.', ['If you can read this, the channel works.']);
        $delivery = $this->deliver($channel, new Notification(($this->ids)(), $siteName, $siteUrl, [$alert], $this->clock->now()));

        return $delivery->delivered
            ? new ChannelOutcome($channelName, ChannelStatus::Sent, 1)
            : new ChannelOutcome($channelName, ChannelStatus::Failed, 1, $delivery->reason);
    }

    /**
     * Tries again the messages whose time has come.
     *
     * @return list<ChannelOutcome>
     */
    public function retryDue(): array
    {
        $outcomes = [];
        $now = $this->clock->now();

        foreach ($this->queue->due($now) as $queued) {
            $channel = $this->channels[$queued->channel] ?? null;
            $keys = array_map(static fn (Alert $alert): string => $alert->key, $queued->notification->alerts);

            // The channel was removed from the settings: the message has nowhere to go
            if (! $channel instanceof Channel) {
                $this->queue->remove($queued->id);

                continue;
            }

            if ($this->limited($queued->channel)) {
                continue;
            }

            $delivery = $this->deliver($channel, $queued->notification);
            $outcomes[] = $this->settle($queued, $delivery, $keys);
        }

        return $outcomes;
    }

    /**
     * @param  list<Alert>  $alerts
     */
    private function dispatchTo(string $name, Channel $channel, array $alerts, string $siteName, string $siteUrl): ChannelOutcome
    {
        $now = $this->clock->now();
        $due = [];

        foreach ($alerts as $alert) {
            $told = $this->state->lastTold($name, $alert->key);
            $stale = ! $told instanceof \DateTimeImmutable || $told->add(new DateInterval('PT'.$this->cooldown.'S')) <= $now;

            if ($alert->severity->atLeast($channel->minSeverity()) && $stale) {
                $due[] = $alert;
            }
        }

        if ($due === []) {
            return new ChannelOutcome($name, ChannelStatus::Nothing);
        }

        if ($this->limited($name)) {
            return new ChannelOutcome($name, ChannelStatus::RateLimited, count($due), 'The channel got its share of messages for this hour.');
        }

        $notification = new Notification(($this->ids)(), $siteName, $siteUrl, $due, $now);
        $keys = array_map(static fn (Alert $alert): string => $alert->key, $due);
        $delivery = $this->deliver($channel, $notification);

        if ($delivery->delivered) {
            $this->state->remember($name, $keys, $now);
            $this->state->recordSent($name, $now);

            return new ChannelOutcome($name, ChannelStatus::Sent, count($due));
        }

        if ($delivery->retryable && ! $this->backoff->exhausted(1)) {
            // Remembered now, so that the next run does not send the same news a second time while this one waits
            $this->state->remember($name, $keys, $now);
            $this->queue->push(new QueuedDelivery(($this->ids)(), $name, $notification, 1, $now->add(new DateInterval('PT'.$this->backoff->delay(1, $delivery->retryAfter).'S'))));

            return new ChannelOutcome($name, ChannelStatus::Queued, count($due), $delivery->reason);
        }

        return new ChannelOutcome($name, ChannelStatus::Failed, count($due), $delivery->reason);
    }

    /**
     * @param  list<string>  $keys
     */
    private function settle(QueuedDelivery $queued, Delivery $delivery, array $keys): ChannelOutcome
    {
        $now = $this->clock->now();
        $alerts = count($queued->notification->alerts);

        if ($delivery->delivered) {
            $this->queue->remove($queued->id);
            $this->state->recordSent($queued->channel, $now);

            return new ChannelOutcome($queued->channel, ChannelStatus::Sent, $alerts);
        }

        $attempts = $queued->attempts + 1;

        if ($delivery->retryable && ! $this->backoff->exhausted($attempts)) {
            $this->queue->push(new QueuedDelivery($queued->id, $queued->channel, $queued->notification, $attempts, $now->add(new DateInterval('PT'.$this->backoff->delay($attempts, $delivery->retryAfter).'S'))));

            return new ChannelOutcome($queued->channel, ChannelStatus::Queued, $alerts, $delivery->reason);
        }

        // Given up: forget what was told, so that the next audit announces it again instead of staying silent
        $this->queue->remove($queued->id);
        $this->state->forget($queued->channel, $keys);

        return new ChannelOutcome($queued->channel, ChannelStatus::Failed, $alerts, $delivery->retryable ? 'Gave up after '.$attempts.' attempts: '.$delivery->reason : $delivery->reason);
    }

    /**
     * A channel that breaks must not stop the others from being told: its failure is reported like any other, by class name only.
     */
    private function deliver(Channel $channel, Notification $notification): Delivery
    {
        try {
            return $channel->send($notification);
        } catch (Throwable $throwable) {
            return Delivery::failed(sprintf('The channel failed unexpectedly (%s).', $throwable::class));
        }
    }

    private function limited(string $channel): bool
    {
        return $this->state->sentSince($channel, $this->clock->now()->sub(new DateInterval('PT1H'))) >= $this->rateLimit;
    }
}
