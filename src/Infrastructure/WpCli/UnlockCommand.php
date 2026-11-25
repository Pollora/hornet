<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WpCli;

use Closure;
use Pollora\Hornet\Application\Login\Unlocker;

/**
 * Let an address back in after it was locked out of the login.
 */
final readonly class UnlockCommand
{
    /**
     * @param  Closure(): Unlocker  $unlocker  built when the command runs: WordPress is loaded by then
     */
    public function __construct(
        private Closure $unlocker,
        private Output $output,
    ) {}

    /**
     * Forget the failed logins and the lockout of one address, or of all.
     *
     * Hornet's lock never stops the command line: this is the way back in if you lock yourself out.
     * (`define('HORNET_DISABLED', true);` in wp-config.php switches Hornet off altogether.)
     *
     * ## OPTIONS
     *
     * [<ip>]
     * : The address to let back in.
     *
     * [--all]
     * : Let every address back in.
     *
     * ## EXAMPLES
     *
     *     wp hornet unlock 203.0.113.7
     *     wp hornet unlock --all
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function __invoke(array $args = [], array $assoc = []): void
    {
        if (array_key_exists('all', $assoc)) {
            $this->output->success(sprintf('Every address is let back in (%d were locked).', ($this->unlocker)()->unlockAll()));

            return;
        }

        $address = $args[0] ?? '';

        if ($address === '') {
            $this->output->error('Usage: wp hornet unlock <ip> | wp hornet unlock --all');

            return;
        }

        if (! ($this->unlocker)()->unlock($address)) {
            $this->output->error('That is not an IP address.');

            return;
        }

        $this->output->success('The address is let back in.');
    }
}
