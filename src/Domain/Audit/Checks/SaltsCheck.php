<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * The keys and salts sign the login cookies and the nonces: a missing, short or sample one lets someone forge them.
 *
 * The finding names the constants that are wrong and never prints a value.
 *
 * @internal
 */
final readonly class SaltsCheck implements Check
{
    private const array CONSTANTS = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ];

    private const string SAMPLE = 'put your unique phrase here';

    public function id(): string
    {
        return 'salts';
    }

    public function label(): string
    {
        return Message::mark('Authentication keys and salts');
    }

    public function run(Environment $environment): Finding
    {
        $weak = [];

        foreach (self::CONSTANTS as $name) {
            $value = $environment->constant($name);

            if (! is_string($value) || strlen($value) < 32 || str_contains(strtolower($value), self::SAMPLE)) {
                $weak[] = $name;
            }
        }

        if ($weak === []) {
            return Finding::pass($this->id(), Message::of('The eight keys and salts are set.'));
        }

        return Finding::fail(
            $this->id(),
            Severity::High,
            Message::plural('%d of the 8 keys and salts is missing, shorter than 32 characters, or the sample value.', '%d of the 8 keys and salts are missing, shorter than 32 characters, or the sample value.', count($weak), count($weak)),
            array_map(Message::raw(...), $weak),
            Message::of('Generate new ones at https://api.wordpress.org/secret-key/1.1/salt/ and replace them in wp-config.php (or the Bedrock .env). This logs everyone out.'),
        );
    }
}
