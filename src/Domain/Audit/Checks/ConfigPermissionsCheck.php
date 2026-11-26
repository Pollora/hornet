<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * wp-config.php holds the database password and the salts: other accounts on the server should not read it.
 *
 * @internal
 */
final readonly class ConfigPermissionsCheck implements Check
{
    public function id(): string
    {
        return 'config-permissions';
    }

    public function label(): string
    {
        return Message::mark('wp-config.php permissions');
    }

    public function run(Environment $environment): Finding
    {
        $file = $environment->configFile();

        if ($file === null) {
            return Finding::unknown($this->id(), Message::of('wp-config.php was not found or cannot be read from here (open_basedir, or the secrets are in the environment).'));
        }

        $mode = $environment->permissions($file);

        if ($mode === null) {
            return Finding::unknown($this->id(), Message::of('The server does not tell the permissions of wp-config.php.'));
        }

        $shown = sprintf('%04o', $mode);

        // Anyone may write it
        if (($mode & 0o002) !== 0) {
            return Finding::fail($this->id(), Severity::High, Message::of('wp-config.php can be written by any account on the server.'), [Message::of('mode %s', $shown)], Message::of('chmod 640 wp-config.php (or 400).'));
        }

        // Anyone may read it
        if (($mode & 0o004) !== 0) {
            return Finding::fail($this->id(), Severity::Low, Message::of('wp-config.php can be read by any account on the server.'), [Message::of('mode %s', $shown)], Message::of('chmod 640 wp-config.php (the WordPress hardening guide advises 400 or 440).'));
        }

        return Finding::pass($this->id(), Message::of('wp-config.php is not readable by other accounts.'), [Message::of('mode %s', $shown)]);
    }
}
