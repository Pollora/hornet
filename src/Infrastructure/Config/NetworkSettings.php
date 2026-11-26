<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Network\Cidr;
use Pollora\Hornet\Domain\Network\TrustedProxies;

/**
 * The `network` settings: which proxies stand in front of the site, so that Hornet can tell who is really asking.
 *
 * Empty by default, which means "no proxy": a forwarding header is never believed.
 *
 * @internal
 */
final class NetworkSettings
{
    private const array KEYS = ['trusted_proxies', 'forwarded_header'];

    /**
     * @throws InvalidSettingsException
     */
    public static function fromArray(mixed $value): TrustedProxies
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('network', 'an array');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'network');
            }
        }

        $header = $value['forwarded_header'] ?? 'X-Forwarded-For';

        if (! is_string($header) || ! in_array($header, TrustedProxies::HEADERS, true)) {
            throw InvalidSettingsException::invalid('network.forwarded_header', 'one of '.implode(', ', TrustedProxies::HEADERS));
        }

        return new TrustedProxies(self::ranges('network.trusted_proxies', $value['trusted_proxies'] ?? []), $header);
    }

    /**
     * @return list<Cidr>
     */
    public static function ranges(string $setting, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidSettingsException::invalid($setting, 'a list of IP addresses or ranges such as 203.0.113.7 or 10.0.0.0/8');
        }

        return array_map(static fn (mixed $range): Cidr => Cidr::parse($setting, is_string($range) ? $range : ''), $value);
    }
}
