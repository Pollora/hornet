<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * The `journal` settings. Off until the owner turns it on: the journal writes to the database, and what it keeps (who logged in, from
 * which network, who changed a role) is personal data. Turning it on is a decision about what the site tells its users.
 *
 * @internal
 */
final readonly class JournalSettings
{
    private const array KEYS = ['enabled', 'retention_days'];

    private function __construct(
        public bool $enabled,
        public int $retentionDays,
    ) {}

    public static function none(): self
    {
        return new self(false, 90);
    }

    /**
     * @throws InvalidSettingsException
     */
    public static function fromArray(mixed $value): self
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('journal', 'an array');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'journal');
            }
        }

        $enabled = $value['enabled'] ?? false;
        $days = $value['retention_days'] ?? 90;

        if (! is_bool($enabled)) {
            throw InvalidSettingsException::invalid('journal.enabled', 'true or false');
        }

        if (! is_int($days) || $days < 1 || $days > 3650) {
            throw InvalidSettingsException::invalid('journal.retention_days', 'a number of days, from 1 to 3650');
        }

        return new self($enabled, $days);
    }
}
