<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use Closure;
use Pollora\Hornet\Application\Journal\RequestContext;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Domain\Network\ClientIp;

/**
 * What WordPress says about the request it is running.
 */
final readonly class WpRequestContext implements RequestContext
{
    /**
     * @param  Closure(): ?ClientIp  $client  who is asking, as the throttle sees it (it believes a forwarding header only from a trusted proxy)
     */
    public function __construct(private Closure $client) {}

    public function source(): Source
    {
        return match (true) {
            defined('WP_CLI') && WP_CLI => Source::Cli,
            wp_doing_cron() => Source::Cron,
            defined('XMLRPC_REQUEST') && XMLRPC_REQUEST => Source::XmlRpc,
            defined('REST_REQUEST') && REST_REQUEST => Source::Rest,
            wp_doing_ajax() => Source::Ajax,
            is_admin() => Source::Admin,
            default => Source::Web,
        };
    }

    public function userId(): int
    {
        return get_current_user_id();
    }

    public function userLogin(): string
    {
        $user = wp_get_current_user();

        return $user->exists() ? $user->user_login : '';
    }

    public function ip(): string
    {
        // The command line and the cron have no visitor
        if ($this->source() === Source::Cli || $this->source() === Source::Cron) {
            return '';
        }

        $client = ($this->client)();

        return $client instanceof ClientIp ? $client->address : '';
    }
}
