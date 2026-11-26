<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\LaravelApplication;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * Debugbar shows the queries, the session and the request of a page. It keeps itself off in production unless it is forced:
 * only that is reported. Only on Pollora.
 */
final readonly class LaravelDebugbarCheck implements Check
{
    public function __construct(private LaravelApplication $application) {}

    public function id(): string
    {
        return 'laravel-debugbar';
    }

    public function label(): string
    {
        return Message::mark('Laravel Debugbar');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), Message::of('Not a production site: not judged.'));
        }

        if (! $this->application->debugbarForced()) {
            return Finding::pass($this->id(), Message::of('Debugbar is not forced on.'));
        }

        $fix = Message::of('Remove DEBUGBAR_FORCE_ALLOW_ENABLE and DEBUGBAR_ENABLED from the .env of the production site, and install Debugbar as a development dependency only.');

        return $this->application->debugbarRestricted()
            ? Finding::fail($this->id(), Severity::Medium, Message::of('Debugbar is forced on in production: the accounts allowed to see it get the queries, the session and the request.'), [], $fix)
            : Finding::fail($this->id(), Severity::High, Message::of('Debugbar is forced on in production: it shows the queries, the session and the request to visitors.'), [], $fix);
    }
}
