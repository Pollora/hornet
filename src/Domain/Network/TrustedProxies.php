<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Network;

/**
 * Tells who is really asking, behind proxies.
 *
 * A forwarding header (`X-Forwarded-For`) is written by whoever sends the request: believing it blindly lets anyone choose
 * their address. It is believed only when the connection comes from a proxy the owner listed, and then read from the right,
 * skipping the trusted proxies: the first address that is not one of them is the visitor.
 */
final readonly class TrustedProxies
{
    /** The forwarding headers that may be read. */
    public const array HEADERS = ['X-Forwarded-For', 'CF-Connecting-IP', 'X-Real-IP'];

    /**
     * @param  list<Cidr>  $proxies
     */
    public function __construct(
        private array $proxies = [],
        private string $header = 'X-Forwarded-For',
    ) {}

    public function header(): string
    {
        return $this->header;
    }

    public function isTrusted(string $address): bool
    {
        foreach ($this->proxies as $proxy) {
            if ($proxy->contains($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string  $remote  the address that opened the connection
     * @param  string|null  $forwarded  the value of the forwarding header, null when there is none
     */
    public function resolve(string $remote, ?string $forwarded): ClientIp
    {
        $present = $forwarded !== null && trim($forwarded) !== '';

        if (! $present) {
            return new ClientIp($remote, $remote);
        }

        if (! $this->isTrusted($remote)) {
            return new ClientIp($remote, $remote, true);
        }

        $hops = array_reverse(array_map(trim(...), explode(',', (string) $forwarded)));

        foreach ($hops as $hop) {
            if (Cidr::pack($hop) === null) {
                // A hop that is not an address cannot be placed: stop at the last one that was
                break;
            }

            if (! $this->isTrusted($hop)) {
                return new ClientIp($hop, $remote);
            }
        }

        return new ClientIp($remote, $remote);
    }
}
