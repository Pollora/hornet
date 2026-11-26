<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * The REST API lists the authors of a site to anyone: their slugs are often their logins, which is half of a password guess.
 *
 * @internal
 */
final readonly class UserEnumerationCheck implements Check
{
    public function id(): string
    {
        return 'user-enumeration';
    }

    public function label(): string
    {
        return Message::mark('User list in the REST API');
    }

    public function run(Environment $environment): Finding
    {
        return match ($environment->usersListedToVisitors()) {
            null => Finding::unknown($this->id(), Message::of('Whether the REST API lists users to visitors could not be found out from here.')),
            true => Finding::fail($this->id(), Severity::Medium, Message::of('The REST API lists the users of the site to visitors.'), [Message::of('/wp/v2/users answers an anonymous request')], Message::of("Turn on Hornet's `hardening.user_enumeration`, or use pollora/headless.")),
            false => Finding::pass($this->id(), Message::of('The REST API does not list users to visitors.')),
        };
    }
}
