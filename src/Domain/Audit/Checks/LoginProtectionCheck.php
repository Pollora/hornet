<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * The login form is where passwords are guessed. pollora/portcullis already protects it: Hornet looks for it, and does not do its work again.
 */
final readonly class LoginProtectionCheck implements Check
{
    public function id(): string
    {
        return 'login-protection';
    }

    public function label(): string
    {
        return 'Login protection';
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->isActive(Component::Portcullis)) {
            return Finding::pass($this->id(), 'pollora/portcullis is installed: it guards the login, locks out brute force and stops account enumeration.');
        }

        return Finding::fail($this->id(), Severity::Low, 'No login protection from Pollora was found.', ['Another plugin or the server may protect the login: Hornet only knows pollora/portcullis.'], 'composer require pollora/portcullis');
    }
}
