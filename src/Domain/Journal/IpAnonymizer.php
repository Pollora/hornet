<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Journal;

/**
 * The address of a visitor, cut so that it no longer points at one person: an IPv4 address loses its last byte (`203.0.113.0`),
 * an IPv6 address everything after its first 48 bits (`2001:db8:1::`). Enough to see "the same network", not enough to name a household.
 * What is not an address gives an empty string.
 */
final class IpAnonymizer
{
    public static function truncate(string $address): string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

        if ($packed === false) {
            return '';
        }

        // ::ffff:203.0.113.7 is an IPv4 address written the long way
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $keep = strlen($packed) === 4 ? 3 : 6;
        $cut = inet_ntop(substr($packed, 0, $keep).str_repeat("\0", strlen($packed) - $keep));

        return $cut === false ? '' : $cut;
    }
}
