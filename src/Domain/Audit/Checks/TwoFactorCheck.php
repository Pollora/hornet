<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * A second factor makes a stolen password worthless. Hornet relies on the official Two-Factor plugin and does not rewrite it.
 *
 * @internal
 */
final readonly class TwoFactorCheck implements Check
{
    public function id(): string
    {
        return 'two-factor';
    }

    public function label(): string
    {
        return Message::mark('Two-factor authentication');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->isActive(Component::TwoFactor)) {
            return Finding::pass($this->id(), Message::of('The Two-Factor plugin is active.'));
        }

        return Finding::fail($this->id(), Severity::Low, Message::of('The Two-Factor plugin is not active.'), [], Message::of('Install the Two-Factor plugin, then require it for the roles you choose with `two_factor.require_roles`.'));
    }
}
