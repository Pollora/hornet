<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Login;

use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Domain\Network\TrustedProxies;

/**
 * Who is asking, read from the request of a classic WordPress or Bedrock: the connection, and the forwarding header only when
 * the connection comes from a proxy the owner listed.
 *
 * @internal
 */
final class ClientAddress
{
    /**
     * @param  array<mixed>  $server  `$_SERVER`
     */
    public static function fromServer(array $server, TrustedProxies $proxies): ?ClientIp
    {
        $remote = $server['REMOTE_ADDR'] ?? null;

        // No connection to speak of: a command line, a cron run
        if (! is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $forwarded = $server['HTTP_'.strtoupper(str_replace('-', '_', $proxies->header()))] ?? null;

        return $proxies->resolve($remote, is_string($forwarded) ? $forwarded : null);
    }
}
