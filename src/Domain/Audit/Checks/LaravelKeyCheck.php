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
 * Without its key Laravel can neither encrypt its cookies nor sign its links. Only on Pollora.
 */
final readonly class LaravelKeyCheck implements Check
{
    public function __construct(private LaravelApplication $application) {}

    public function id(): string
    {
        return 'laravel-app-key';
    }

    public function label(): string
    {
        return Message::mark('Laravel application key');
    }

    public function run(Environment $environment): Finding
    {
        if (! $this->application->hasKey()) {
            return Finding::fail($this->id(), Severity::High, Message::of('APP_KEY is not set: Laravel can neither encrypt nor sign anything.'), [], Message::of('Run `php artisan key:generate` once, and keep the value secret.'));
        }

        return Finding::pass($this->id(), Message::of('The application key is set.'));
    }
}
