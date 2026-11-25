<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Admin;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanState;

/**
 * What the administration screen shows of the audit and of the last scan, as plain data for the page's script.
 *
 * The text of a finding comes from the site (a file name, a plugin slug, a title from a feed): the script writes it as text, never as HTML.
 */
final class StatusView
{
    /**
     * @param  array<string, string>  $labels  the name of each check, by id
     * @param  list<string>  $scanTasks  the ids of the tasks of the scan, in the order they run
     * @return array<string, mixed>
     */
    public static function build(Report $report, array $labels, ?ScanSnapshot $scan, ScanState $state, DateTimeImmutable $now, array $scanTasks): array
    {
        $findings = array_map(static fn (Finding $finding): array => self::finding($finding, $labels), $report->findings);

        // What asks for attention first: the open ones, the worst first; then what could not be verified; then the rest
        usort($findings, static fn (array $a, array $b): int => [self::order($a['status']), -self::rank($a['severity'])] <=> [self::order($b['status']), -self::rank($b['severity'])]);

        $now = $now->getTimestamp();

        return [
            'counts' => $report->counts(),
            'worst' => $report->worst()?->value,
            'findings' => $findings,
            'scan' => [
                'at' => $scan?->at->getTimestamp(),
                'age' => $scan instanceof ScanSnapshot ? max(0, $now - $scan->at->getTimestamp()) : null,
                'unfinished' => $scan->unfinished ?? [],
                'running' => $state->isActive($now),
                'status' => $state->isActive($now) ? $state->status : ScanState::IDLE,
                'task' => $state->isActive($now) ? $state->task : null,
                'tasks' => array_map(static fn (string $id): array => self::task($id, $labels, $scan), $scanTasks),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array{check: string, label: string, status: string, outcome: string, severity: string, summary: string, details: list<string>, fix: ?string, ignored: ?string}
     */
    private static function finding(Finding $finding, array $labels): array
    {
        return [
            'check' => $finding->checkId,
            'label' => $labels[$finding->checkId] ?? $finding->checkId,
            'status' => self::status($finding),
            'outcome' => $finding->outcome->value,
            'severity' => $finding->severity->value,
            'summary' => $finding->summary,
            'details' => $finding->details,
            'fix' => $finding->fix,
            'ignored' => $finding->ignoredReason,
        ];
    }

    /**
     * Where a task of the scan stands, from the last scan: it never runs one.
     *
     * @param  array<string, string>  $labels
     * @return array{id: string, label: string, status: string, summary: string}
     */
    private static function task(string $id, array $labels, ?ScanSnapshot $scan): array
    {
        $finding = $scan?->finding($id);

        return [
            'id' => $id,
            'label' => $labels[$id] ?? $id,
            'status' => $finding instanceof Finding ? self::status($finding) : 'none',
            'summary' => $finding instanceof Finding ? $finding->summary : 'Not scanned yet.',
        ];
    }

    private static function status(Finding $finding): string
    {
        return match (true) {
            $finding->outcome === Outcome::Unknown => 'unknown',
            $finding->isIgnored() => 'ignored',
            $finding->outcome === Outcome::Pass => 'pass',
            default => 'open',
        };
    }

    private static function order(string $status): int
    {
        return match ($status) {
            'open' => 0,
            'unknown' => 1,
            'ignored' => 2,
            default => 3,
        };
    }

    private static function rank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}
