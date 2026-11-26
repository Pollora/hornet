<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * Code that is installed and not used can often still be reached, and its flaws with it; nobody watches it. For themes, the rule
 * is the one of WordPress: the active theme, its parent and one default theme to fall back on may stay.
 */
final readonly class InactiveExtensionsCheck implements Check
{
    private const int LISTED = 5;

    private function __construct(private bool $themes) {}

    public static function plugins(): self
    {
        return new self(false);
    }

    public static function themes(): self
    {
        return new self(true);
    }

    public function id(): string
    {
        return $this->themes ? 'inactive-themes' : 'inactive-plugins';
    }

    public function label(): string
    {
        return $this->themes ? Message::mark('Inactive themes') : Message::mark('Inactive plugins');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), Message::of('Not a production site: not judged.'));
        }

        $unused = $this->themes ? $environment->unusedThemes() : $environment->inactivePlugins();

        if ($unused === null) {
            return Finding::pass($this->id(), Message::of('On a network another site may use what this one does not: not judged.'));
        }

        $count = count($unused);

        if ($count === 0) {
            return Finding::pass($this->id(), $this->themes ? Message::of('No theme is installed without a use.') : Message::of('Every installed plugin is active.'));
        }

        $details = array_slice($unused, 0, self::LISTED);

        if ($count > self::LISTED) {
            $details[] = Message::plural('and %d more', 'and %d more', $count - self::LISTED, $count - self::LISTED);
        }

        return Finding::fail(
            $this->id(),
            Severity::Low,
            $this->themes
                ? Message::plural('%d theme is installed and not used.', '%d themes are installed and not used.', $count, $count)
                : Message::plural('%d plugin is installed and not active.', '%d plugins are installed and not active.', $count, $count),
            $details,
            $this->themes
                ? Message::of('Delete the themes you do not use (or composer remove them). Keep the active one, its parent and one default theme.')
                : Message::of('Delete what you do not use (or composer remove it): its files can still be reached, and its flaws with them.'),
        );
    }
}
