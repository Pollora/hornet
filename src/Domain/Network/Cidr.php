<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Network;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * An address or a range of addresses (`203.0.113.7`, `10.0.0.0/8`, `2001:db8::/32`), IPv4 or IPv6.
 *
 * An IPv4 address written as IPv6 (`::ffff:203.0.113.7`) is the same address, so it is compared as IPv4.
 */
final readonly class Cidr
{
    /**
     * @param  string  $network  the packed address (4 or 16 bytes)
     */
    private function __construct(
        private string $network,
        private int $bits,
    ) {}

    /**
     * @throws InvalidSettingsException
     */
    public static function parse(string $setting, string $value): self
    {
        [$address, $length] = [...explode('/', trim($value), 2), null];
        $packed = self::pack((string) $address);

        if ($packed === null) {
            throw InvalidSettingsException::invalid($setting, 'a list of IP addresses or ranges such as 203.0.113.7 or 10.0.0.0/8');
        }

        $max = strlen($packed) * 8;

        if ($length === null) {
            return new self($packed, $max);
        }

        if (preg_match('/\A\d{1,3}\z/', $length) !== 1 || (int) $length > $max) {
            throw InvalidSettingsException::invalid($setting, 'a list of IP addresses or ranges such as 203.0.113.7 or 10.0.0.0/8');
        }

        return new self($packed, (int) $length);
    }

    public function contains(string $address): bool
    {
        $packed = self::pack($address);

        if ($packed === null || strlen($packed) !== strlen($this->network)) {
            return false;
        }

        $whole = intdiv($this->bits, 8);

        if (substr($packed, 0, $whole) !== substr($this->network, 0, $whole)) {
            return false;
        }

        $rest = $this->bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($this->network[$whole]) & $mask);
    }

    /**
     * The packed form of an address, null when it is not an address. IPv4-mapped IPv6 comes back as IPv4.
     */
    public static function pack(string $address): ?string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xFF\xFF")) {
            return substr($packed, 12);
        }

        return $packed;
    }
}
