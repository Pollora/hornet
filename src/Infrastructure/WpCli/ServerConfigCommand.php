<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WpCli;

use Closure;
use Pollora\Hornet\Domain\Exceptions\ServerRulesException;
use Pollora\Hornet\Domain\Server\ServerRules;

/**
 * Prints the rules for the web server that no plugin can apply. It writes nothing, anywhere.
 *
 * @internal
 */
final readonly class ServerConfigCommand
{
    /**
     * @param  Closure(): string  $uploads  the URL path of the uploads folder; read when the command runs, WordPress is loaded by then
     */
    public function __construct(
        private Closure $uploads,
        private Output $output,
    ) {}

    /**
     * Print rules for nginx, Apache or an .htaccess file: no PHP from the uploads folder, no dotfile, no file that should never be public.
     *
     * Nothing is written to the server: read the rules, put them where they belong, test the configuration, reload.
     *
     * ## OPTIONS
     *
     * <server>
     * : nginx, apache (virtual host) or htaccess.
     *
     * [--uploads=<path>]
     * : The URL path of the uploads folder, such as /wp-content/uploads or /app/uploads. By default the one of this site.
     *
     * [--headers]
     * : Also set the headers that never change (nosniff, referrer, permissions). Not with the `headers` settings of Hornet on.
     *
     * ## EXAMPLES
     *
     *     wp hornet server-config nginx > hornet.conf
     *     wp hornet server-config apache --headers
     *     wp hornet server-config htaccess
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function __invoke(array $args = [], array $assoc = []): void
    {
        $uploads = isset($assoc['uploads']) && is_string($assoc['uploads']) ? $assoc['uploads'] : ($this->uploads)();

        try {
            $rules = ServerRules::for($args[0] ?? '', $uploads, array_key_exists('headers', $assoc));
        } catch (ServerRulesException $serverRulesException) {
            $this->output->error($serverRulesException->getMessage());

            return;
        }

        foreach (explode("\n", rtrim($rules, "\n")) as $line) {
            $this->output->line($line);
        }
    }
}
