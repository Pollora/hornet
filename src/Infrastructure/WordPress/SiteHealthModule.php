<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * One Site Health test per check, so the person who opens Tools > Site Health sees the same findings as `wp hornet status`.
 *
 * The audit runs once per request, when the first test is asked for.
 */
final class SiteHealthModule implements Module
{
    private ?Report $report = null;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Environment $environment,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('site_status_tests', $this->tests(...));
    }

    /**
     * @param  array<string, mixed>  $tests
     * @return array<string, mixed>
     */
    public function tests(array $tests): array
    {
        $direct = is_array($tests['direct'] ?? null) ? $tests['direct'] : [];

        foreach ($this->auditor->checks() as $check) {
            $direct['hornet_'.str_replace('-', '_', $check->id())] = [
                'label' => 'Hornet: '.$check->label(),
                'test' => fn (): array => $this->result($check),
            ];
        }

        $tests['direct'] = $direct;

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public function result(Check $check): array
    {
        $this->report ??= $this->auditor->run($this->environment);
        $finding = $this->report->find($check->id()) ?? Finding::unknown($check->id(), 'The check did not run.');

        return self::format($finding, $check->label());
    }

    /**
     * The shape Site Health expects: a status (good, recommended or critical), a label, a badge, a description and actions.
     *
     * @return array<string, mixed>
     */
    public static function format(Finding $finding, string $label): array
    {
        $description = '<p>'.esc_html($finding->summary).'</p>';

        if ($finding->details !== []) {
            $description .= '<ul>'.implode('', array_map(static fn (string $line): string => '<li>'.esc_html($line).'</li>', $finding->details)).'</ul>';
        }

        if ($finding->isIgnored()) {
            $description .= '<p>'.esc_html('Set aside on purpose: '.$finding->ignoredReason).'</p>';
        }

        [$status, $badge] = match (true) {
            $finding->outcome === Outcome::Unknown => ['good', ['label' => 'Not verified', 'color' => 'gray']],
            $finding->outcome === Outcome::Pass || $finding->isIgnored() => ['good', ['label' => 'Security', 'color' => 'blue']],
            $finding->severity->atLeast(Severity::High) => ['critical', ['label' => 'Security', 'color' => 'red']],
            default => ['recommended', ['label' => 'Security', 'color' => 'orange']],
        };

        return [
            'label' => $label,
            'status' => $status,
            'badge' => $badge,
            'description' => $description,
            'actions' => $finding->isOpen() && $finding->fix !== null ? '<p>'.esc_html($finding->fix).'</p>' : '',
            'test' => 'hornet_'.str_replace('-', '_', $finding->checkId),
        ];
    }
}
