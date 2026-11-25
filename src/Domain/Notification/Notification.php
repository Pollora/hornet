<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * One message to one channel: a few alerts grouped, so that a night of findings is one message and not twelve.
 */
final readonly class Notification
{
    /**
     * @param  non-empty-list<Alert>  $alerts
     */
    public function __construct(
        public string $id,
        public string $siteName,
        public string $siteUrl,
        public array $alerts,
        public DateTimeImmutable $createdAt,
    ) {}

    /** The most serious alert decides how loud the message is. */
    public function severity(): Severity
    {
        $worst = Severity::Info;

        foreach ($this->alerts as $alert) {
            if ($alert->severity->atLeast($worst)) {
                $worst = $alert->severity;
            }
        }

        return $worst;
    }

    public function subject(): string
    {
        $count = count($this->alerts);

        return sprintf('[Hornet] %s: %d security finding%s (%s)', $this->siteName, $count, $count === 1 ? '' : 's', $this->severity()->value);
    }

    /**
     * @return array{id: string, site_name: string, site_url: string, created_at: string, alerts: list<array{key: string, severity: string, title: string, details: list<string>, fix: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site_name' => $this->siteName,
            'site_url' => $this->siteUrl,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'alerts' => array_map(static fn (Alert $alert): array => $alert->toArray(), $this->alerts),
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $alerts = [];

        foreach (is_array($data['alerts'] ?? null) ? $data['alerts'] : [] as $alert) {
            if (is_array($alert)) {
                $alerts[] = Alert::fromArray($alert);
            }
        }

        if ($alerts === [] || ! is_string($data['id'] ?? null) || ! is_string($data['created_at'] ?? null)) {
            return null;
        }

        return new self($data['id'], is_string($data['site_name'] ?? null) ? $data['site_name'] : '', is_string($data['site_url'] ?? null) ? $data['site_url'] : '', $alerts, new DateTimeImmutable($data['created_at']));
    }
}
