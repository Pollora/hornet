<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use Composer\InstalledVersions;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\SecurityScore;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Admin\SettingsView;
use Throwable;

/**
 * The Hornet section of Tools > Site Health > Info, which people copy into a support request.
 *
 * What would tell a reader where the site is weak (the protections that are on, the score) is marked private: WordPress shows it on the
 * screen and leaves it out of the copy. No secret, address or URL is in it: the lines are those of the Settings screen.
 *
 * The audit runs only when WordPress asks for the debug information.
 *
 * @internal
 */
final readonly class SiteHealthInfo
{
    /**
     * @param  Closure(): Auditor  $auditor
     */
    public function __construct(
        private Settings $settings,
        private Closure $auditor,
        private Environment $environment,
        private ?Translator $translator = null,
    ) {}

    /**
     * @param  array<string, mixed>  $information
     * @return array<string, mixed>
     */
    public function information(array $information): array
    {
        $information['pollora-hornet'] = [
            'label' => 'Hornet',
            'fields' => [
                'version' => $this->field(Message::of('Version'), self::version()),
                'protections' => $this->field(Message::of('Turned on'), $this->turnedOn(), true),
                'last_scan' => $this->field(Message::of('Last scan'), $this->lastScan()),
                'score' => $this->field(Message::of('Security score'), $this->score(), true),
            ],
        ];

        return $information;
    }

    /**
     * @param  string|array<string, string>  $value
     * @return array{label: string, value: string|array<string, string>, private: bool}
     */
    private function field(Message $label, string|array $value, bool $private = false): array
    {
        return ['label' => $label->render($this->translator), 'value' => $value, 'private' => $private];
    }

    private static function version(): string
    {
        return InstalledVersions::isInstalled('pollora/hornet') ? (string) InstalledVersions::getPrettyVersion('pollora/hornet') : '?';
    }

    /**
     * @return string|array<string, string>
     */
    private function turnedOn(): string|array
    {
        $on = [];

        foreach (SettingsView::build($this->settings, $this->translator) as $row) {
            if ($row['on']) {
                $on[$row['key']] = $row['detail'] === '' ? $row['label'] : $row['label'].' ('.$row['detail'].')';
            }
        }

        return $on === [] ? Message::of('Nothing')->render($this->translator) : $on;
    }

    private function lastScan(): string
    {
        try {
            $scan = $this->environment->lastScan();
        } catch (Throwable) {
            return Message::of('Could not be read')->render($this->translator);
        }

        return $scan?->at->format('Y-m-d H:i \U\T\C') ?? Message::of('Never')->render($this->translator);
    }

    /**
     * An audit that fails gives no number: it is said, never a clean score.
     */
    private function score(): string
    {
        try {
            $score = SecurityScore::of(($this->auditor)()->run($this->environment));
        } catch (Throwable) {
            return Message::of('Could not be read')->render($this->translator);
        }

        $said = Message::of('%1$d out of 100, grade %2$s', $score->value, $score->grade);

        if ($score->isProvisional()) {
            return $said->render($this->translator).' ('.Message::of('part of the audit could not be verified')->render($this->translator).')';
        }

        return $said->render($this->translator);
    }
}
