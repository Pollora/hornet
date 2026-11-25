<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Admin;

use Pollora\Hornet\Application\Admin\StatusView;
use Pollora\Hornet\Application\Audit\SetAsideService;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Exceptions\SetAsideException;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\ChannelSpec;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Notification\NotificationFactory;
use Pollora\Hornet\Infrastructure\WordPress\AuditorFactory;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Infrastructure\WordPress\ScannerFactory;

/**
 * What the screen asks of the site, in one place: where it stands, the journal, setting a finding aside, a test message.
 *
 * The routes check who is asking; this does the work. It reads and writes nothing of the site's settings.
 */
final readonly class AdminBackend
{
    public function __construct(
        private Settings $settings,
        private Environment $environment,
        private ScanJob $job,
        private ?JournalRecorder $recorder,
    ) {}

    /**
     * The audit, the last scan, the settings, the channels. It runs the audit, which is not instant.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $auditor = AuditorFactory::forWordPress($this->settings);
        $labels = [];

        foreach ($auditor->checks() as $check) {
            $labels[$check->id()] = $check->label();
        }

        $view = StatusView::build($auditor->run($this->environment), $labels, (new OptionScanStore)->last(), $this->job->state(), (new SystemClock)->now(), ScannerFactory::forWordPress($this->settings)?->tasks() ?? [], (new OptionSetAsideStore)->all(), array_keys($this->settings->ignored));

        return [
            ...$view,
            'settings' => SettingsView::build($this->settings),
            // By name and kind only: where a channel goes is a secret
            'channels' => array_map(static fn (ChannelSpec $channel): array => ['name' => $channel->name, 'type' => $channel->type->value, 'min_severity' => $channel->minSeverity->value], $this->settings->notifications->channels),
            'network' => is_multisite(),
            'journal' => ['enabled' => $this->settings->journal->enabled, 'retention_days' => $this->settings->journal->retentionDays],
        ];
    }

    /**
     * One page of the journal, the newest first.
     *
     * @param  array{type?: string, source?: string, user?: int, page?: int, per_page?: int}  $params
     * @return array<string, mixed>
     */
    public function journal(array $params): array
    {
        if (! $this->settings->journal->enabled) {
            return ['enabled' => false, 'events' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'types' => EventTypes::groups()];
        }

        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 25)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $type = isset($params['type']) && $params['type'] !== '' ? $params['type'] : null;
        $source = isset($params['source']) && $params['source'] !== '' ? Source::tryFrom($params['source']) : null;
        $user = isset($params['user']) && (int) $params['user'] > 0 ? (int) $params['user'] : null;
        $journal = new TableJournal;
        $total = $journal->count(new JournalQuery($type, $source, $user));
        $events = $journal->find(new JournalQuery($type, $source, $user, null, $perPage, ($page - 1) * $perPage));

        return [
            'enabled' => true,
            'events' => array_map(static fn (Event $event): array => $event->toArray(), $events),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'types' => EventTypes::groups(),
            'retention_days' => $this->settings->journal->retentionDays,
        ];
    }

    /**
     * @throws SetAsideException
     */
    public function setAside(string $check, string $reason): SetAside
    {
        $user = wp_get_current_user();

        return $this->service()->setAside($check, $reason, $user->ID, $user->user_login);
    }

    /**
     * @throws SetAsideException
     */
    public function restore(string $check): void
    {
        $this->service()->restore($check);
    }

    /**
     * @return array{status: string, reason: string}|null null when there is no such channel
     */
    public function testChannel(string $name): ?array
    {
        if (! in_array($name, array_map(static fn (ChannelSpec $channel): string => $channel->name, $this->settings->notifications->channels), true)) {
            return null;
        }

        $outcome = NotificationFactory::forWordPress()->service($this->settings, $this->environment, get_bloginfo('name'), home_url())->test($name);

        return ['status' => $outcome->status->value, 'reason' => $outcome->reason];
    }

    private function service(): SetAsideService
    {
        $auditor = AuditorFactory::forWordPress($this->settings);

        return new SetAsideService(
            new OptionSetAsideStore,
            new SystemClock,
            static fn (): array => array_map(static fn (Check $check): string => $check->id(), $auditor->checks()),
            fn (): array => array_map(static fn (Finding $finding): string => $finding->checkId, $auditor->run($this->environment)->open()),
            fn (): array => array_keys($this->settings->ignored),
            fn (string $type, string $subject, array $details): bool => $this->recorder?->record($type, $subject, $details) ?? false,
        );
    }
}
