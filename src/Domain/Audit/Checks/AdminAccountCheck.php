<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * A user named "admin" is the first login every password guesser tries.
 */
final readonly class AdminAccountCheck implements Check
{
    public function id(): string
    {
        return 'admin-account';
    }

    public function label(): string
    {
        return Message::mark('Default administrator name');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->userExists('admin')) {
            return Finding::fail($this->id(), Severity::Medium, Message::of('A user is named "admin".'), [], Message::of('Create an administrator with another login, hand the content over to it, and delete "admin".'));
        }

        return Finding::pass($this->id(), Message::of('No user is named "admin".'));
    }
}
