<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WpCli;

use Closure;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scalar;

/**
 * Look at the files of the site: compare the core with WordPress.org.
 *
 * @internal
 */
final readonly class ScanCommand
{
    private const array FORMATS = ['table', 'json', 'csv', 'yaml'];

    /**
     * @param  Closure(): ?Scanner  $scanner  built when the command runs; null when Hornet is switched off, every scan is disabled or the install cannot be read
     */
    public function __construct(
        private Closure $scanner,
        private Output $output,
    ) {}

    /**
     * Scan the files and list what they show.
     *
     * Reads the disk and asks api.wordpress.org for the checksums of this version of WordPress (only the version and the
     * locale are sent). It changes no file: it never deletes, repairs or quarantines anything. The result is kept, and
     * `wp hornet status` and Site Health show it.
     *
     * Exits with an error when a finding at or above --fail-on is open, so a deployment can stop on it.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * [--fail-on=<severity>]
     * : The lowest open severity that makes the command fail: info, low, medium, high, critical, or none.
     * ---
     * default: high
     * ---
     *
     * [--repin]
     * : Accept the core files listed in scanner.allow_modified as they are now (after you changed a patch on purpose).
     *
     * ## EXAMPLES
     *
     *     wp hornet scan
     *     wp hornet scan --format=json --fail-on=medium
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function __invoke(array $args = [], array $assoc = []): void
    {
        $format = Scalar::string($assoc['format'] ?? 'table');
        $failOn = Scalar::string($assoc['fail-on'] ?? 'high');

        if (! in_array($format, self::FORMATS, true)) {
            $this->output->error(sprintf('Unknown format "%s": use %s.', $format, implode(', ', self::FORMATS)));

            return;
        }

        $threshold = $failOn === 'none' ? null : Severity::tryFrom($failOn);

        if ($failOn !== 'none' && ! $threshold instanceof Severity) {
            $this->output->error(sprintf('Unknown severity "%s": use info, low, medium, high, critical or none.', $failOn));

            return;
        }

        $scanner = ($this->scanner)();

        if (! $scanner instanceof Scanner) {
            $this->output->error('Hornet is switched off, every scan is disabled, or the WordPress files cannot be read from here: nothing was scanned.');

            return;
        }

        $snapshot = $scanner->run(array_key_exists('repin', $assoc));
        $report = $scanner->report($snapshot);

        if ($format === 'json') {
            $this->output->line((string) json_encode(['scanned_at' => $snapshot->at->format('c'), 'counts' => $report->counts(), 'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $report->findings)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->output->items(array_map($this->row(...), $report->findings), ['status', 'severity', 'check', 'summary'], $format);
            $this->say($report);
        }

        if ($threshold instanceof Severity && $report->hasOpenAtLeast($threshold)) {
            $this->output->error(sprintf('Open findings at %s or above.', $threshold->value));
        }
    }

    /**
     * @return array<string, string>
     */
    private function row(Finding $finding): array
    {
        $status = match (true) {
            $finding->outcome === Outcome::Unknown => 'unknown',
            $finding->isIgnored() => 'ignored',
            $finding->outcome === Outcome::Pass => 'pass',
            default => 'open',
        };

        return ['status' => $status, 'severity' => $finding->outcome === Outcome::Fail ? $finding->severity->value : '-', 'check' => $finding->checkId, 'summary' => $finding->summary];
    }

    private function say(Report $report): void
    {
        foreach ($report->findings as $finding) {
            foreach ($finding->details as $detail) {
                $this->output->line('  '.$detail);
            }
        }

        $open = $report->open();

        if ($open === []) {
            $this->output->success('Nothing found.');

            return;
        }

        $this->output->warning(sprintf('%d open %s.', count($open), count($open) === 1 ? 'finding' : 'findings'));

        foreach ($open as $finding) {
            if ($finding->fix !== null) {
                $this->output->line(sprintf('  %s: %s', $finding->checkId, $finding->fix));
            }
        }
    }
}
