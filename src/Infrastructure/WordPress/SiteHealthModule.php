<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\I18n\FindingTranslator;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;

/**
 * One Site Health test per check, so the person who opens Tools > Site Health sees the same findings as `wp hornet status`.
 *
 * The audit runs once per request, when the first test is asked for.
 *
 * @internal
 */
final class SiteHealthModule implements Module
{
    private ?Report $report = null;

    private ?Auditor $built = null;

    /**
     * @param  Auditor|Closure(): Auditor  $auditor
     */
    public function __construct(
        private readonly Auditor|Closure $auditor,
        private readonly Environment $environment,
        private readonly ?Translator $translator = null,
        private readonly ?SiteHealthInfo $information = null,
    ) {}

    /**
     * The auditor, built when Site Health first asks: what is set aside from the screen is read from the database, and a request that is not Site Health does not pay for it.
     */
    private function auditor(): Auditor
    {
        return $this->built ??= $this->auditor instanceof Closure ? ($this->auditor)() : $this->auditor;
    }

    /** The checks whose subject Site Health already covers. */
    private const array SAID_BY_WORDPRESS = ['plugin-updates', 'theme-updates', 'inactive-plugins', 'inactive-themes'];

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('site_status_tests', $this->tests(...));

        if ($this->information instanceof SiteHealthInfo) {
            $hooks->addFilter('debug_information', $this->information->information(...));
        }
    }

    /**
     * @param  array<string, mixed>  $tests
     * @return array<string, mixed>
     */
    public function tests(array $tests): array
    {
        $direct = is_array($tests['direct'] ?? null) ? $tests['direct'] : [];

        foreach ($this->auditor()->checks() as $check) {
            // Site Health has its own tests of the plugins and the themes: the same news is not said twice on one page
            if (in_array($check->id(), self::SAID_BY_WORDPRESS, true)) {
                continue;
            }

            $direct['hornet_'.str_replace('-', '_', $check->id())] = [
                'label' => 'Hornet: '.Message::known($check->label())->render($this->translator),
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
        $this->report ??= $this->auditor()->run($this->environment);
        $finding = $this->report->find($check->id()) ?? Finding::unknown($check->id(), Message::of('The check did not run.'));

        return self::format($finding, Message::known($check->label())->render($this->translator), $this->translator);
    }

    /**
     * The shape Site Health expects: a status (good, recommended or critical), a label, a badge, a description and actions.
     *
     * @return array<string, mixed>
     */
    public static function format(Finding $finding, string $label, ?Translator $translator = null): array
    {
        $description = '<p>'.esc_html(FindingTranslator::summary($finding, $translator)).'</p>';

        if ($finding->details !== []) {
            $description .= '<ul>'.implode('', array_map(static fn (string $line): string => '<li>'.esc_html($line).'</li>', FindingTranslator::details($finding, $translator))).'</ul>';
        }

        if ($finding->isIgnored()) {
            $description .= '<p>'.esc_html(Message::of('Set aside on purpose: %s', (string) $finding->ignoredReason)->render($translator)).'</p>';
        }

        [$status, $badge] = match (true) {
            $finding->outcome === Outcome::Unknown => ['good', ['label' => Message::of('Not verified')->render($translator), 'color' => 'gray']],
            $finding->outcome === Outcome::Pass || $finding->isIgnored() => ['good', ['label' => Message::of('Security')->render($translator), 'color' => 'blue']],
            $finding->severity->atLeast(Severity::High) => ['critical', ['label' => Message::of('Security')->render($translator), 'color' => 'red']],
            default => ['recommended', ['label' => Message::of('Security')->render($translator), 'color' => 'orange']],
        };

        return [
            'label' => $label,
            'status' => $status,
            'badge' => $badge,
            'description' => $description,
            'actions' => $finding->isOpen() && $finding->fix !== null ? '<p>'.esc_html((string) FindingTranslator::fix($finding, $translator)).'</p>' : '',
            'test' => 'hornet_'.str_replace('-', '_', $finding->checkId),
        ];
    }
}
