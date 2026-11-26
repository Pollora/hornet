<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Network;

/**
 * Who is asking: the address a rule should be applied to.
 *
 * @internal
 */
final readonly class ClientIp
{
    public function __construct(
        /** The address of the visitor, as best as it can be told. */
        public string $address,
        /** The address that opened the connection to the server: a visitor, or the proxy in front of the site. */
        public string $remote,
        /** Whether a forwarding header came with the request and was not believed, because its sender is not a trusted proxy. */
        public bool $forwardedIgnored = false,
    ) {}

    /**
     * Whether this "visitor" is in fact an address that many visitors share: the proxy or the load balancer in front of the site
     * (a private, local or reserved address that is the very address that opened the connection). A rule per address would then
     * apply to everybody at once, and one attacker could lock every visitor out.
     */
    public function isSharedAddress(): bool
    {
        if ($this->address !== $this->remote) {
            return false;
        }

        return filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * What identifies the address in storage: a hash, so that the address itself is not kept (it is personal data).
     */
    public function key(string $salt): string
    {
        $packed = (string) Cidr::pack($this->address);

        // An IPv6 network is a /64: whoever has one has as many addresses as they like, and each would be a fresh start
        if (strlen($packed) === 16) {
            $packed = substr($packed, 0, 8).str_repeat("\0", 8);
        }

        return hash_hmac('sha256', bin2hex($packed), $salt);
    }
}
