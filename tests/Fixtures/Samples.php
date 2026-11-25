<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\Notification;

/**
 * Notifications and alerts, ready made.
 */
final class Samples
{
    public static function alert(string $key = 'finding:xml-rpc:medium', Severity $severity = Severity::Medium, string $title = 'XML-RPC is enabled.'): Alert
    {
        return new Alert($key, $severity, $title, ['detail one'], 'Turn it off.');
    }

    /**
     * @param  list<Alert>|null  $alerts
     */
    public static function notification(?array $alerts = null, string $id = 'abcdef0123456789'): Notification
    {
        /** @var non-empty-list<Alert> $alerts */
        $alerts ??= [self::alert()];

        return new Notification($id, 'My site', 'https://example.com', $alerts, new DateTimeImmutable('2026-11-25 10:00:00 UTC'));
    }
}
