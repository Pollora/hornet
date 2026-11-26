<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Notification;

use Pollora\Hornet\Application\AlertFactory;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\I18n\Translator;
use Pollora\Hornet\Domain\Notification\RetryQueue;

/**
 * What the cron and `wp hornet notify` do: audit, tell the channels what is new, try the waiting messages again.
 */
final readonly class NotificationService
{
    /**
     * @param  list<ChannelInfo>  $channels
     */
    public function __construct(
        private Auditor $auditor,
        private Environment $environment,
        private Notifier $notifier,
        private RunLock $lock,
        private RetryQueue $queue,
        private string $siteName,
        private string $siteUrl,
        private array $channels,
        private ?Translator $translator = null,
    ) {}

    /**
     * @return list<ChannelInfo>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    public function queued(): int
    {
        return $this->queue->count();
    }

    /**
     * Audits the site, tells every channel what it has not heard yet, then tries the waiting messages again.
     */
    public function run(): AuditRun
    {
        if (! $this->lock->acquire()) {
            return new AuditRun(null, []);
        }

        try {
            $report = $this->auditor->run($this->environment);
            $outcomes = [...$this->notifier->dispatch(AlertFactory::fromReport($report, $this->translator), $this->siteName, $this->siteUrl), ...$this->notifier->retryDue()];
        } finally {
            $this->lock->release();
        }

        return new AuditRun($report, $outcomes);
    }

    /**
     * @return list<ChannelOutcome>
     */
    public function retry(): array
    {
        return $this->notifier->retryDue();
    }

    public function test(string $channel): ChannelOutcome
    {
        return $this->notifier->test($channel, $this->siteName, $this->siteUrl);
    }
}
