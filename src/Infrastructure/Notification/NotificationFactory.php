<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification;

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Notification\ChannelInfo;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Application\Notification\Notifier;
use Pollora\Hornet\Application\Notification\RunLock;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Mailer;
use Pollora\Hornet\Domain\Notification\NotificationState;
use Pollora\Hornet\Domain\Notification\RetryQueue;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\ChannelSpec;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\OptionNotificationState;
use Pollora\Hornet\Infrastructure\WordPress\OptionRetryQueue;
use Pollora\Hornet\Infrastructure\WordPress\OptionRunLock;
use Pollora\Hornet\Infrastructure\WordPress\WpHttpClient;
use Pollora\Hornet\Infrastructure\WordPress\WpMailer;

/**
 * Puts the notification service together from the settings.
 */
final readonly class NotificationFactory
{
    public function __construct(
        private HttpClient $http,
        private Mailer $mailer,
        private Clock $clock,
        private NotificationState $state,
        private RetryQueue $queue,
        private RunLock $lock,
    ) {}

    /**
     * The real thing, for a running WordPress.
     */
    public static function forWordPress(): self
    {
        return new self(new WpHttpClient, new WpMailer, new SystemClock, new OptionNotificationState, new OptionRetryQueue, new OptionRunLock);
    }

    /**
     * @param  string  $siteName  the name of the site, as WordPress knows it
     *
     * @throws \InvalidArgumentException when the settings name a check that does not exist
     */
    public function service(Settings $settings, Environment $environment, string $siteName, string $siteUrl): NotificationService
    {
        $channels = (new ChannelFactory($this->http, $this->mailer, $this->clock))->build($settings->notifications->channels);

        $notifier = new Notifier(
            $channels,
            $this->state,
            $this->queue,
            $this->clock,
            new Backoff,
            $settings->notifications->cooldown,
            $settings->notifications->rateLimit,
            static fn (): string => bin2hex(random_bytes(8)),
        );

        return new NotificationService(
            new Auditor(DefaultChecks::all(), $settings->disabledChecks, $settings->ignored),
            $environment,
            $notifier,
            $this->lock,
            $this->queue,
            Text::truncate(Text::clean($siteName), 100) ?: 'WordPress',
            Text::clean($siteUrl),
            array_map(static fn (ChannelSpec $spec): ChannelInfo => new ChannelInfo($spec->name, $spec->type->value, $spec->minSeverity), $settings->notifications->channels),
        );
    }
}
