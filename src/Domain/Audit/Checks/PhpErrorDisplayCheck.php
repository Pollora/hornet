<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * With WP_DEBUG off, WordPress leaves `display_errors` as PHP has it: a fatal error then shows paths to the visitor.
 * Not part of the audit on Pollora, where Laravel turns the setting off itself.
 *
 * @internal
 */
final readonly class PhpErrorDisplayCheck implements Check
{
    public function id(): string
    {
        return 'php-error-display';
    }

    public function label(): string
    {
        return Message::mark('PHP error display');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), Message::of('Not a production site: not judged.'));
        }

        if ($environment->constant('WP_DEBUG') === true) {
            return Finding::pass($this->id(), Message::of('WP_DEBUG is on: what is shown is judged by the Debug mode check.'));
        }

        $displayed = $environment->displaysPhpErrors();

        if ($displayed === null) {
            return Finding::unknown($this->id(), Message::of('Not seen yet: it is read from the web server when somebody opens the dashboard.'));
        }

        if ($displayed) {
            return Finding::fail($this->id(), Severity::Medium, Message::of('PHP shows its errors to visitors, although WP_DEBUG is off.'), [], Message::of('Set display_errors to Off in php.ini, in the PHP-FPM pool or in .user.ini.'));
        }

        return Finding::pass($this->id(), Message::of('PHP does not show its errors.'));
    }
}
