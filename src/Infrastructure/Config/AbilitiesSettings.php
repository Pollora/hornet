<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * The `abilities` settings: what an AI agent (through the Abilities API of WordPress, and the MCP Adapter on top of it) may ask.
 *
 * Off until the owner turns it on. `writes` is a second, separate switch: reading what the audit found is one decision, letting an agent
 * start a scan or set a finding aside is another. Neither can be turned on from an ability, the screen or REST: only the settings file.
 *
 * @internal
 */
final readonly class AbilitiesSettings
{
    private const array KEYS = ['enabled', 'writes'];

    private function __construct(
        public bool $enabled,
        public bool $writes,
    ) {}

    public static function none(): self
    {
        return new self(false, false);
    }

    /**
     * @throws InvalidSettingsException
     */
    public static function fromArray(mixed $value): self
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('abilities', 'an array');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'abilities');
            }
        }

        $enabled = $value['enabled'] ?? false;
        $writes = $value['writes'] ?? false;

        if (! is_bool($enabled)) {
            throw InvalidSettingsException::invalid('abilities.enabled', 'true or false');
        }

        if (! is_bool($writes)) {
            throw InvalidSettingsException::invalid('abilities.writes', 'true or false');
        }

        // Writing without reading is no use, and a half-open door is a door that someone forgot to close
        if ($writes && ! $enabled) {
            throw InvalidSettingsException::invalid('abilities.writes', 'false unless abilities.enabled is true');
        }

        return new self($enabled, $writes);
    }
}
