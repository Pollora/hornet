<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WpCli;

use Closure;
use InvalidArgumentException;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scalar;

/**
 * Audit the security of the site from the command line.
 *
 * @internal
 */
final readonly class HornetCommand
{
    private const array FORMATS = ['table', 'json', 'csv', 'yaml'];

    /**
     * @param  Closure(): Report  $report  built when a command runs: wp-config.php has been read by then
     */
    public function __construct(
        private Closure $report,
        private Output $output,
    ) {}

    /**
     * Run the security checks and list what they found.
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
     * [--all]
     * : Also list the checks that pass.
     *
     * ## EXAMPLES
     *
     *     wp hornet status
     *     wp hornet status --format=json --fail-on=medium
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function status(array $args = [], array $assoc = []): void
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

        try {
            $report = ($this->report)();
        } catch (InvalidArgumentException $invalidArgumentException) {
            // Wrong settings: say so, and stop
            $this->output->error($invalidArgumentException->getMessage());

            return;
        }

        $all = array_key_exists('all', $assoc);
        $findings = $all ? $report->findings : $this->shown($report);

        if ($format === 'json') {
            $this->output->line((string) json_encode(['counts' => $report->counts(), 'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $findings)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } elseif ($findings !== []) {
            $this->output->items(array_map($this->row(...), $findings), ['status', 'severity', 'check', 'summary'], $format);
        }

        $this->say($report, $format);

        if ($threshold instanceof Severity && $report->hasOpenAtLeast($threshold)) {
            $this->output->error(sprintf('Open findings at %s or above.', $threshold->value));
        }
    }

    /**
     * What a person needs to read: the open findings, most serious first, then what could not be verified.
     *
     * @return list<Finding>
     */
    private function shown(Report $report): array
    {
        $unknown = array_values(array_filter($report->findings, static fn (Finding $finding): bool => $finding->outcome === Outcome::Unknown));

        return [...$report->open(), ...$unknown];
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

    private function say(Report $report, string $format): void
    {
        if ($format === 'json') {
            return;
        }

        $counts = $report->counts();
        $line = sprintf('%d checks: %d pass, %d open, %d ignored, %d not verified.', $counts['total'], $counts['pass'], $counts['open'], $counts['ignored'], $counts['unknown']);

        if ($counts['open'] === 0) {
            $this->output->success($line);

            return;
        }

        $this->output->warning($line);

        foreach ($report->open() as $finding) {
            if ($finding->fix !== null) {
                $this->output->line(sprintf('  %s: %s', $finding->checkId, $finding->fix));
            }
        }
    }
}
