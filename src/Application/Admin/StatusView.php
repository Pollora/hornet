<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Admin;

use DateTimeImmutable;
use Pollora\Hornet\Application\I18n\FindingTranslator;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\SecurityScore;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanState;

/**
 * What the administration screen shows of the audit and of the last scan, as plain data for the page's script.
 *
 * The text of a finding comes from the site (a file name, a plugin slug, a title from a feed): the script writes it as text, never as HTML.
 *
 * @internal
 *
 * @phpstan-type FindingRow array{check: string, label: string, status: string, outcome: string, severity: string, summary: string, details: list<string>, fix: ?string, ignored: ?string, ignored_by: ?string, ignored_login: ?string, ignored_at: ?int, can_set_aside: bool, can_restore: bool}
 */
final class StatusView
{
    /**
     * @param  array<string, string>  $labels  the name of each check, by id
     * @param  list<string>  $scanTasks  the ids of the tasks of the scan, in the order they run
     * @param  array<string, SetAside>  $setAside  the findings set aside from the screen, by check
     * @param  list<string>  $inFile  the checks that the settings file sets aside: they win, and the screen cannot change them
     * @return array{counts: array<string, int>, score: array<string, mixed>, worst: ?string, findings: list<FindingRow>, scan: array<string, mixed>}
     */
    public static function build(Report $report, array $labels, ?ScanSnapshot $scan, ScanState $state, DateTimeImmutable $now, array $scanTasks, array $setAside = [], array $inFile = [], ?Translator $translator = null): array
    {
        // What asks for attention first: the open ones, the worst first; then what could not be verified; then the rest
        $sorted = $report->findings;
        usort($sorted, static fn (Finding $a, Finding $b): int => [self::order(self::status($a)), -self::rank($a->severity->value)] <=> [self::order(self::status($b)), -self::rank($b->severity->value)]);
        $findings = array_values(array_map(static fn (Finding $finding): array => self::finding($finding, $labels, $setAside, $inFile, $translator), $sorted));

        $now = $now->getTimestamp();

        return [
            'counts' => $report->counts(),
            'score' => SecurityScore::of($report)->toArray(),
            'worst' => $report->worst()?->value,
            'findings' => $findings,
            'scan' => [
                'at' => $scan?->at->getTimestamp(),
                'age' => $scan instanceof ScanSnapshot ? max(0, $now - $scan->at->getTimestamp()) : null,
                'unfinished' => $scan->unfinished ?? [],
                'running' => $state->isActive($now),
                'status' => $state->isActive($now) ? $state->status : ScanState::IDLE,
                'task' => $state->isActive($now) ? $state->task : null,
                'tasks' => array_map(static fn (string $id): array => self::task($id, $labels, $scan, $translator), $scanTasks),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, SetAside>  $setAside
     * @param  list<string>  $inFile
     * @return FindingRow
     */
    private static function finding(Finding $finding, array $labels, array $setAside, array $inFile, ?Translator $translator): array
    {
        $status = self::status($finding);
        $locked = in_array($finding->checkId, $inFile, true);
        $aside = $setAside[$finding->checkId] ?? null;

        return [
            'check' => $finding->checkId,
            'label' => self::label($labels, $finding->checkId, $translator),
            'status' => $status,
            'outcome' => $finding->outcome->value,
            'severity' => $finding->severity->value,
            'summary' => FindingTranslator::summary($finding, $translator),
            'details' => FindingTranslator::details($finding, $translator),
            'fix' => FindingTranslator::fix($finding, $translator),
            'ignored' => $finding->ignoredReason,
            // Who set it aside, and where: the file wins over the screen
            'ignored_by' => $finding->isIgnored() ? ($locked ? 'file' : 'screen') : null,
            'ignored_login' => $finding->isIgnored() && ! $locked && $aside instanceof SetAside ? $aside->login : null,
            'ignored_at' => $finding->isIgnored() && ! $locked && $aside instanceof SetAside ? $aside->at : null,
            'can_set_aside' => $status === 'open' && ! $locked,
            'can_restore' => $finding->isIgnored() && ! $locked && $aside instanceof SetAside,
        ];
    }

    /**
     * Where a task of the scan stands, from the last scan: it never runs one.
     *
     * @param  array<string, string>  $labels
     * @return array{id: string, label: string, status: string, summary: string}
     */
    private static function task(string $id, array $labels, ?ScanSnapshot $scan, ?Translator $translator): array
    {
        $finding = $scan?->finding($id);

        return [
            'id' => $id,
            'label' => self::label($labels, $id, $translator),
            'status' => $finding instanceof Finding ? self::status($finding) : 'none',
            'summary' => $finding instanceof Finding ? FindingTranslator::summary($finding, $translator) : Message::of('Not scanned yet.')->render($translator),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function label(array $labels, string $id, ?Translator $translator): string
    {
        $label = $labels[$id] ?? null;

        return $label === null ? $id : Message::known($label)->render($translator);
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
