<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;

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
     * A scan of the core that found nothing, made on the day the fake environment calls today.
     */
    public static function cleanScan(string $at = '2026-11-24 10:00:00'): ScanSnapshot
    {
        return new ScanSnapshot(new DateTimeImmutable($at.' UTC'), ['core-integrity' => Finding::pass('core-integrity', 'The core files match.'), 'plugin-integrity' => Finding::pass('plugin-integrity', 'The plugins match.'), 'theme-integrity' => Finding::pass('theme-integrity', 'The themes match.'), 'suspicious-files' => Finding::pass('suspicious-files', 'Nothing suspicious.'), 'exposed-files' => Finding::pass('exposed-files', 'Nothing given away.'), 'security-headers' => Finding::pass('security-headers', 'The headers are sent.')]);
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
