<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Login\ThrottlePolicy;
use Pollora\Hornet\Domain\Network\Cidr;

/**
 * The `login` settings: the fallback throttle for sites that do not run pollora/portcullis. Off until it is asked for.
 */
final readonly class LoginSettings
{
    private const array KEYS = ['throttle', 'max_attempts', 'window', 'lockout', 'allow_ips'];

    /**
     * @param  list<Cidr>  $allowIps  addresses that are never counted nor locked out
     */
    private function __construct(
        public bool $throttle,
        public ThrottlePolicy $policy,
        public array $allowIps,
    ) {}

    public static function none(): self
    {
        return new self(false, new ThrottlePolicy, []);
    }

    /**
     * @throws InvalidSettingsException
     */
    public static function fromArray(mixed $value): self
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('login', 'an array');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'login');
            }
        }

        $throttle = $value['throttle'] ?? false;

        if (! is_bool($throttle)) {
            throw InvalidSettingsException::invalid('login.throttle', 'true or false');
        }

        return new self(
            $throttle,
            new ThrottlePolicy(self::integer($value, 'max_attempts', 5, 1, 100), self::integer($value, 'window', 900, 60, 86400), self::integer($value, 'lockout', 900, 60, 86400)),
            NetworkSettings::ranges('login.allow_ips', $value['allow_ips'] ?? []),
        );
    }

    public function isAllowed(string $address): bool
    {
        foreach ($this->allowIps as $range) {
            if ($range->contains($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function integer(array $value, string $key, int $default, int $min, int $max): int
    {
        $number = $value[$key] ?? $default;

        if (! is_int($number) || $number < $min || $number > $max) {
            throw InvalidSettingsException::invalid('login.'.$key, sprintf('a whole number from %d to %d', $min, $max));
        }

        return $number;
    }
}
