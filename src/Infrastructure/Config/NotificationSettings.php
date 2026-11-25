<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Notification\Secret;

/**
 * The `notifications` settings: where alerts go, how often the audit runs, how calm the channels are kept.
 *
 * A secret (a webhook URL, a token, a signing key) is written as a reference: `['env' => 'HORNET_SLACK_URL']` or
 * `['constant' => 'HORNET_SLACK_URL']`. A plain string is accepted too, for a Pollora `config/hornet.php` that calls `env()` itself.
 * Nothing is ever read from, or written to, the database.
 */
final readonly class NotificationSettings
{
    public const array SCHEDULES = ['off', 'hourly', 'twicedaily', 'daily'];

    private const array KEYS = ['schedule', 'cooldown', 'rate_limit', 'channels'];

    private const array CHANNEL_KEYS = ['type', 'min_severity'];

    /**
     * @param  list<ChannelSpec>  $channels
     */
    private function __construct(
        public string $schedule,
        public int $cooldown,
        public int $rateLimit,
        public array $channels,
    ) {}

    public static function none(): self
    {
        return new self('daily', 86400, 20, []);
    }

    /**
     * @throws InvalidSettingsException
     */
    public static function fromArray(mixed $value, SecretSource $secrets): self
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('notifications', 'an array');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'notifications');
            }
        }

        $schedule = $value['schedule'] ?? 'daily';

        if (! is_string($schedule) || ! in_array($schedule, self::SCHEDULES, true)) {
            throw InvalidSettingsException::invalid('notifications.schedule', implode(', ', self::SCHEDULES));
        }

        return new self(
            $schedule,
            self::integer($value, 'cooldown', 86400, 60, 2592000),
            self::integer($value, 'rate_limit', 20, 1, 1000),
            self::channels($value['channels'] ?? [], $secrets),
        );
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function integer(array $value, string $key, int $default, int $min, int $max): int
    {
        $number = $value[$key] ?? $default;

        if (! is_int($number) || $number < $min || $number > $max) {
            throw InvalidSettingsException::invalid('notifications.'.$key, sprintf('a whole number from %d to %d', $min, $max));
        }

        return $number;
    }

    /**
     * @return list<ChannelSpec>
     */
    private static function channels(mixed $value, SecretSource $secrets): array
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('notifications.channels', 'a map of channel name => settings');
        }

        $channels = [];

        foreach ($value as $name => $config) {
            $channels[] = self::channel((string) $name, $config, $secrets);
        }

        return $channels;
    }

    private static function channel(string $name, mixed $config, SecretSource $secrets): ChannelSpec
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,39}\z/', $name) !== 1) {
            throw InvalidSettingsException::channel($name, 'a channel name is lowercase letters, digits, "-" and "_" (40 characters at most).');
        }

        if (! is_array($config)) {
            throw InvalidSettingsException::channel($name, 'its settings must be an array.');
        }

        $type = ChannelType::tryFrom(is_string($config['type'] ?? null) ? $config['type'] : '');

        if (! $type instanceof ChannelType) {
            throw InvalidSettingsException::channel($name, 'its "type" must be one of: '.implode(', ', array_map(static fn (ChannelType $case): string => $case->value, ChannelType::cases())).'.');
        }

        $severity = Severity::tryFrom(is_string($config['min_severity'] ?? null) ? $config['min_severity'] : 'medium');

        if (! $severity instanceof Severity) {
            throw InvalidSettingsException::channel($name, 'its "min_severity" must be info, low, medium, high or critical.');
        }

        $known = $type->options();

        foreach (array_keys($config) as $key) {
            if (! in_array($key, self::CHANNEL_KEYS, true) && ! isset($known[$key])) {
                throw InvalidSettingsException::channel($name, sprintf('unknown option "%s" for a %s channel.', $key, $type->value));
            }
        }

        $options = [];

        foreach ($known as $option => $rule) {
            if (! array_key_exists($option, $config)) {
                if ($rule['required']) {
                    throw InvalidSettingsException::channel($name, sprintf('the option "%s" is required.', $option));
                }

                continue;
            }

            $options[$option] = self::option($name, $option, $rule['kind'], $config[$option], $secrets);
        }

        return new ChannelSpec($name, $type, $severity, self::checkAddresses($name, $type, $options));
    }

    private static function option(string $channel, string $option, string $kind, mixed $value, SecretSource $secrets): mixed
    {
        return match ($kind) {
            'secret', 'secret-url' => self::secret($channel, $option, $value, $secrets),
            'bool' => is_bool($value) ? $value : throw InvalidSettingsException::channel($channel, sprintf('"%s" must be true or false.', $option)),
            'chat' => (is_int($value) || (is_string($value) && $value !== '')) ? (string) $value : throw InvalidSettingsException::channel($channel, 'the "chat_id" must be a number or a @channel name.'),
            default => self::emails($channel, $value),
        };
    }

    private static function secret(string $channel, string $option, mixed $value, SecretSource $secrets): Secret
    {
        if (is_string($value) && $value !== '') {
            return Secret::of($value);
        }

        if (is_array($value) && count($value) === 1 && (isset($value['env']) || isset($value['constant'])) && is_string(reset($value))) {
            $source = array_key_first($value);
            $name = (string) reset($value);
            $resolved = $source === 'env' ? $secrets->env($name) : $secrets->constant($name);

            if ($resolved === null) {
                throw InvalidSettingsException::channel($channel, sprintf('the %s "%s" that holds "%s" is not set.', $source === 'env' ? 'environment variable' : 'constant', $name, $option));
            }

            return Secret::of($resolved);
        }

        throw InvalidSettingsException::channel($channel, sprintf('"%s" must be a string, or ["env" => "NAME"], or ["constant" => "NAME"].', $option));
    }

    /**
     * @return list<string>
     */
    private static function emails(string $channel, mixed $value): array
    {
        $list = is_string($value) ? [$value] : $value;

        if (! is_array($list) || $list === [] || ! array_is_list($list)) {
            throw InvalidSettingsException::channel($channel, '"to" must be an e-mail address or a list of them.');
        }

        foreach ($list as $address) {
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw InvalidSettingsException::channel($channel, '"to" holds an address that is not valid.');
            }
        }

        return $list;
    }

    /**
     * A service URL must be https, and name a host. Plain http only where the owner says so (a self-hosted receiver), for the channels that allow it.
     * The message never repeats the URL: it is the secret.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function checkAddresses(string $channel, ChannelType $type, array $options): array
    {
        $url = $options['url'] ?? null;

        if (! $url instanceof Secret) {
            return $options;
        }

        $parts = parse_url($url->reveal());
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $insecureAllowed = ($options['insecure'] ?? false) === true;

        if (! is_array($parts) || ($parts['host'] ?? '') === '' || $scheme !== 'https' && ! ($scheme === 'http' && $insecureAllowed)) {
            throw InvalidSettingsException::channel($channel, 'the "url" must be an https address'.(in_array($type, [ChannelType::Webhook, ChannelType::Ntfy], true) ? ' (http only with "insecure" => true).' : '.'));
        }

        return $options;
    }
}
