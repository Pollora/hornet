<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Domain\Network\TrustedProxies;

/**
 * The settings of the package, checked once and read as an object.
 *
 * A key that is not listed here is refused: a typo must not leave a protection off.
 *
 * @internal
 */
final readonly class Settings
{
    private const array KEYS = ['enabled', 'disabled_checks', 'ignore', 'site_health', 'notifications', 'hardening', 'headers', 'network', 'login', 'passwords', 'session', 'two_factor', 'scanner', 'vulnerabilities', 'admin', 'journal', 'abilities'];

    /**
     * @param  list<string>  $disabledChecks  ids of the checks that do not run
     * @param  array<string, string>  $ignored  id of a check => the reason its finding is set aside
     */
    private function __construct(
        public bool $enabled,
        public array $disabledChecks,
        public array $ignored,
        public bool $siteHealth,
        public NotificationSettings $notifications,
        public HardeningSettings $hardening,
        public SecurityHeaders $headers,
        public TrustedProxies $network,
        public LoginSettings $login,
        public AccountSettings $accounts,
        public ScannerSettings $scanner,
        public VulnerabilitySettings $vulnerabilities,
        public bool $admin,
        public JournalSettings $journal,
        public AbilitiesSettings $abilities,
    ) {}

    /**
     * @param  array<mixed>  $config
     *
     * @throws InvalidSettingsException
     */
    public static function fromArray(array $config, ?SecretSource $secrets = null): self
    {
        foreach (array_keys($config) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, '');
            }
        }

        return new self(
            self::boolean($config, 'enabled', true),
            self::ids($config['disabled_checks'] ?? []),
            self::ignored($config['ignore'] ?? []),
            self::boolean($config, 'site_health', true),
            array_key_exists('notifications', $config) ? NotificationSettings::fromArray($config['notifications'], $secrets ?? new EnvironmentSecrets) : NotificationSettings::none(),
            array_key_exists('hardening', $config) ? HardeningSettings::fromArray($config['hardening']) : HardeningSettings::none(),
            array_key_exists('headers', $config) ? HeaderSettings::fromArray($config['headers']) : new SecurityHeaders,
            array_key_exists('network', $config) ? NetworkSettings::fromArray($config['network']) : new TrustedProxies,
            array_key_exists('login', $config) ? LoginSettings::fromArray($config['login']) : LoginSettings::none(),
            AccountSettings::fromConfig($config),
            array_key_exists('scanner', $config) ? ScannerSettings::fromArray($config['scanner']) : ScannerSettings::none(),
            array_key_exists('vulnerabilities', $config) ? VulnerabilitySettings::fromArray($config['vulnerabilities']) : VulnerabilitySettings::none(),
            self::boolean($config, 'admin', true),
            array_key_exists('journal', $config) ? JournalSettings::fromArray($config['journal']) : JournalSettings::none(),
            array_key_exists('abilities', $config) ? AbilitiesSettings::fromArray($config['abilities']) : AbilitiesSettings::none(),
        );
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function boolean(array $config, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $config)) {
            return $default;
        }

        return is_bool($config[$key]) ? $config[$key] : throw InvalidSettingsException::invalid($key, 'true or false');
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidSettingsException::invalid('disabled_checks', 'a list of check ids');
        }

        $ids = [];

        foreach ($value as $id) {
            $ids[] = is_string($id) && $id !== '' ? $id : throw InvalidSettingsException::invalid('disabled_checks', 'a list of check ids');
        }

        return $ids;
    }

    /**
     * Each entry gives the check and why its finding is accepted: an accepted risk without a reason is refused.
     *
     * @return array<string, string>
     */
    private static function ignored(mixed $value): array
    {
        $expected = "a list of ['check' => id, 'reason' => why] entries";

        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidSettingsException::invalid('ignore', $expected);
        }

        $ignored = [];

        foreach ($value as $entry) {
            if (! is_array($entry) || ! is_string($entry['check'] ?? null) || ! is_string($entry['reason'] ?? null) || trim($entry['reason']) === '' || $entry['check'] === '') {
                throw InvalidSettingsException::invalid('ignore', $expected);
            }

            $ignored[$entry['check']] = trim($entry['reason']);
        }

        return $ignored;
    }
}
