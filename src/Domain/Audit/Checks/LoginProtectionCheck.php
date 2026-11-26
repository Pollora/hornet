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
 * The login form is where passwords are guessed. pollora/portcullis already protects it: Hornet looks for it, and does not do
 * its work again. Without it, Hornet's own throttle is the fallback, and the check says whether that throttle can be trusted.
 *
 * @internal
 */
final readonly class LoginProtectionCheck implements Check
{
    public function id(): string
    {
        return 'login-protection';
    }

    public function label(): string
    {
        return Message::mark('Login protection');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->isActive(Component::Portcullis)) {
            return Finding::pass($this->id(), Message::of('pollora/portcullis is installed: it guards the login, locks out brute force and stops account enumeration.'));
        }

        if ($environment->isActive(Component::HornetThrottle)) {
            return match ($environment->loginThrottleProblem()) {
                'shared' => Finding::fail($this->id(), Severity::High, Message::of('The login throttle is on but cannot tell visitors apart: every request seems to come from one private address, the proxy or load balancer in front of the site, so it refuses to lock anybody.'), [Message::of('Locking that address would lock every visitor out at once.')], Message::of('List the proxy in `network.trusted_proxies` (and set `network.forwarded_header` if it is not X-Forwarded-For).')),
                'forwarded-untrusted' => Finding::fail($this->id(), Severity::High, Message::of('The login throttle sees a forwarding header from an address that is not a trusted proxy. If the site is behind a CDN or a proxy, visitors share its address and can lock each other out.'), [Message::of('Requests are counted under the address that opened the connection, which a header cannot change.')], Message::of('List the CDN or proxy in `network.trusted_proxies`.')),
                default => Finding::pass($this->id(), Message::of("Hornet's login throttle is on: an address that fails too often is locked out for a while.")),
            };
        }

        return Finding::fail($this->id(), Severity::Low, Message::of('No login protection from Pollora was found.'), [Message::of('Another plugin or the server may protect the login: Hornet only knows pollora/portcullis and its own throttle.')], Message::of("composer require pollora/portcullis, or turn on Hornet's `login.throttle`."));
    }
}
