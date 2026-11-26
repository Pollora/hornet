<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Abilities;

use Pollora\Hornet\Domain\Journal\EventDetails;
use Pollora\Hornet\Domain\Journal\IpAnonymizer;

/**
 * What an agent is given: the same findings as the screen, but every piece of text cut short and cleaned of control and invisible
 * characters, and every list bounded.
 *
 * What the audit reports comes partly from the site (a file name, a plugin slug, a reason somebody typed): it is data, and a model that reads
 * it must not be able to take it for an instruction. So nothing here is free text of any length, and the descriptions of the abilities say
 * that these values are data. No secret is in a finding to begin with; this does not rely on that: it only ever copies the fields below.
 *
 * @internal
 */
final class AgentView
{
    public const int MAX_LIMIT = 50;

    private const int TEXT = 300;

    private const int LINE = 200;

    private const int LINES = 5;

    public const array STATUSES = ['attention', 'open', 'unknown', 'ignored', 'pass', 'all'];

    /**
     * @param  array<string, mixed>  $status  what the screen is given
     * @return array<string, mixed>
     */
    public static function summary(array $status): array
    {
        $scan = is_array($status['scan'] ?? null) ? $status['scan'] : [];

        return [
            'counts' => self::counts($status['counts'] ?? []),
            'score' => is_int($status['score']['value'] ?? null) ? $status['score']['value'] : null,
            'grade' => is_string($status['score']['grade'] ?? null) ? self::text($status['score']['grade'], 1) : null,
            'worst_severity' => is_string($status['worst'] ?? null) ? self::text($status['worst'], 20) : null,
            'last_scan_seconds_ago' => is_int($scan['age'] ?? null) ? $scan['age'] : null,
            'scan_running' => (bool) ($scan['running'] ?? false),
            'scan_task' => is_string($scan['task'] ?? null) ? self::text($scan['task'], 60) : null,
            'journal_enabled' => (bool) ($status['journal']['enabled'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public static function findings(array $status, string $show, ?string $severity, int $limit, int $offset): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $all = [];

        foreach (is_array($status['findings'] ?? null) ? $status['findings'] : [] as $finding) {
            if (! is_array($finding) || ! self::matches($finding, $show, $severity)) {
                continue;
            }

            $all[] = self::finding($finding);
        }

        return ['total' => count($all), 'items' => array_slice($all, $offset, $limit)];
    }

    /**
     * What to do about one finding.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>|null null when there is no such check
     */
    public static function remediation(array $status, string $check): ?array
    {
        foreach (is_array($status['findings'] ?? null) ? $status['findings'] : [] as $finding) {
            if (is_array($finding) && ($finding['check'] ?? null) === $check) {
                return [...self::finding($finding), 'fix' => is_string($finding['fix'] ?? null) ? self::text($finding['fix'], 600) : null];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $page  what the journal gives the screen
     * @return array<string, mixed>
     */
    public static function journal(array $page, int $limit): array
    {
        $events = [];

        foreach (array_slice(is_array($page['events'] ?? null) ? $page['events'] : [], 0, max(1, min(self::MAX_LIMIT, $limit))) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $events[] = [
                'at' => is_int($event['at'] ?? null) ? $event['at'] : 0,
                'type' => self::text((string) ($event['type'] ?? ''), 60),
                'source' => self::text((string) ($event['source'] ?? ''), 20),
                'actor' => self::text((string) ($event['actor'] ?? ''), 60),
                'subject' => self::text((string) ($event['subject'] ?? ''), 120),
                'network' => IpAnonymizer::truncate(self::text((string) ($event['ip'] ?? ''), 45)),
                'details' => EventDetails::clean(is_array($event['details'] ?? null) ? $event['details'] : []),
            ];
        }

        return [
            'enabled' => (bool) ($page['enabled'] ?? false),
            'total' => is_int($page['total'] ?? null) ? $page['total'] : 0,
            'page' => is_int($page['page'] ?? null) ? $page['page'] : 1,
            'pages' => is_int($page['pages'] ?? null) ? $page['pages'] : 1,
            'events' => $events,
        ];
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private static function matches(array $finding, string $show, ?string $severity): bool
    {
        $status = (string) ($finding['status'] ?? '');

        $statusOk = match ($show) {
            'all' => true,
            'attention' => in_array($status, ['open', 'unknown'], true),
            default => $status === $show,
        };

        return $statusOk && ($severity === null || ($finding['severity'] ?? null) === $severity);
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private static function finding(array $finding): array
    {
        $details = [];

        foreach (array_slice(is_array($finding['details'] ?? null) ? array_values($finding['details']) : [], 0, self::LINES) as $line) {
            $details[] = self::text((string) $line, self::LINE);
        }

        return [
            'check' => self::text((string) ($finding['check'] ?? ''), 60),
            'label' => self::text((string) ($finding['label'] ?? ''), 100),
            'status' => self::text((string) ($finding['status'] ?? ''), 20),
            'severity' => self::text((string) ($finding['severity'] ?? ''), 20),
            'summary' => self::text((string) ($finding['summary'] ?? ''), self::TEXT),
            'details' => $details,
            'set_aside_reason' => is_string($finding['ignored'] ?? null) ? self::text($finding['ignored'], self::TEXT) : null,
            'set_aside_by' => is_string($finding['ignored_by'] ?? null) ? self::text($finding['ignored_by'], 10) : null,
            'can_set_aside' => (bool) ($finding['can_set_aside'] ?? false),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function counts(mixed $counts): array
    {
        $clean = [];

        foreach (is_array($counts) ? $counts : [] as $name => $count) {
            if (is_int($count) && preg_match('/^[a-z_]{1,20}$/D', (string) $name) === 1) {
                $clean[(string) $name] = $count;
            }
        }

        return $clean;
    }

    private static function text(string $text, int $length): string
    {
        return EventDetails::text($text, $length);
    }
}
