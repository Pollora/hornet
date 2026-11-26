<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * Without HTTPS the login cookie and the password travel in clear.
 *
 * @internal
 */
final readonly class HttpsCheck implements Check
{
    public function id(): string
    {
        return 'https';
    }

    public function label(): string
    {
        return Message::mark('HTTPS');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), Message::of('Not a production site: not judged.'));
        }

        if (! $environment->usesHttps()) {
            return Finding::fail($this->id(), Severity::High, Message::of('The site address does not use HTTPS.'), [], Message::of('Serve the site over HTTPS and set the WordPress and site addresses to https://.'));
        }

        // FORCE_SSL_ADMIN must be a constant: a plugin cannot set it early enough
        if ($environment->constant('FORCE_SSL_ADMIN') !== true) {
            return Finding::fail($this->id(), Severity::Low, Message::of('The login and the dashboard are not forced onto HTTPS.'), [], Message::of("Define('FORCE_SSL_ADMIN', true) in wp-config.php."));
        }

        return Finding::pass($this->id(), Message::of('The site uses HTTPS, and the dashboard is forced onto it.'));
    }
}
