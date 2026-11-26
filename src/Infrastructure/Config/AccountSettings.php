<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * The `passwords`, `session` and `two_factor` settings: the rules about accounts. All off until they are asked for.
 *
 * @internal
 */
final readonly class AccountSettings
{
    /**
     * @param  list<string>  $requireRoles  roles that must set up a second factor
     */
    private function __construct(
        public bool $checkBreached,
        public int $breachedThreshold,
        public ?int $sessionLifetime,
        public ?int $rememberLifetime,
        public array $requireRoles,
        public ?DateTimeImmutable $enforceAfter,
    ) {}

    public static function none(): self
    {
        return new self(false, 1, null, null, [], null);
    }

    /**
     * Whether any rule is on.
     */
    public function isActive(): bool
    {
        return $this->checkBreached || $this->sessionLifetime !== null || $this->rememberLifetime !== null || $this->requireRoles !== [];
    }

    /**
     * @param  array<mixed>  $config  the whole settings array
     *
     * @throws InvalidSettingsException
     */
    public static function fromConfig(array $config): self
    {
        $passwords = self::section($config, 'passwords', ['check_breached', 'breached_threshold']);
        $session = self::section($config, 'session', ['lifetime', 'remember_lifetime']);
        $twoFactor = self::section($config, 'two_factor', ['require_roles', 'enforce_after']);

        $check = $passwords['check_breached'] ?? false;

        if (! is_bool($check)) {
            throw InvalidSettingsException::invalid('passwords.check_breached', 'true or false');
        }

        return new self(
            $check,
            self::integer($passwords, 'passwords', 'breached_threshold', 1, 1, 1000000) ?? 1,
            self::integer($session, 'session', 'lifetime', null, 900, 1209600),
            self::integer($session, 'session', 'remember_lifetime', null, 900, 31536000),
            self::roles($twoFactor['require_roles'] ?? []),
            self::date($twoFactor['enforce_after'] ?? null),
        );
    }

    /**
     * @param  array<mixed>  $config
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    private static function section(array $config, string $name, array $keys): array
    {
        if (! array_key_exists($name, $config)) {
            return [];
        }

        $section = $config[$name];

        if (! is_array($section)) {
            throw InvalidSettingsException::invalid($name, 'an array');
        }

        foreach (array_keys($section) as $key) {
            if (! in_array($key, $keys, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, $name);
            }
        }

        return $section;
    }

    /**
     * @param  array<mixed>  $section
     */
    private static function integer(array $section, string $name, string $key, ?int $default, int $min, int $max): ?int
    {
        $value = $section[$key] ?? $default;

        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            throw InvalidSettingsException::invalid($name.'.'.$key, sprintf('a whole number from %d to %d', $min, $max));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function roles(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidSettingsException::invalid('two_factor.require_roles', 'a list of role names such as administrator');
        }

        foreach ($value as $role) {
            if (! is_string($role) || preg_match('/\A[a-z0-9_-]{1,60}\z/', $role) !== 1) {
                throw InvalidSettingsException::invalid('two_factor.require_roles', 'a list of role names such as administrator');
            }
        }

        return $value;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;

        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw InvalidSettingsException::invalid('two_factor.enforce_after', 'a date written YYYY-MM-DD');
        }

        return $date;
    }
}
