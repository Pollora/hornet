<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * The `hardening` settings: what Hornet closes on the site. Everything is off until it is asked for.
 *
 * @internal
 */
final readonly class HardeningSettings
{
    private const array KEYS = ['xmlrpc', 'user_enumeration', 'hide_version', 'file_editor'];

    private function __construct(
        public bool $xmlRpc,
        public bool $userEnumeration,
        public bool $hideVersion,
        public bool $fileEditor,
    ) {}

    public static function none(): self
    {
        return new self(false, false, false, false);
    }

    public function isActive(): bool
    {
        return $this->xmlRpc || $this->userEnumeration || $this->hideVersion || $this->fileEditor;
    }

    /**
     * @throws InvalidSettingsException
     */
    public static function fromArray(mixed $value): self
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('hardening', 'an array');
        }

        foreach ($value as $key => $setting) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'hardening');
            }

            if (! is_bool($setting)) {
                throw InvalidSettingsException::invalid('hardening.'.$key, 'true or false');
            }
        }

        return new self($value['xmlrpc'] ?? false, $value['user_enumeration'] ?? false, $value['hide_version'] ?? false, $value['file_editor'] ?? false);
    }
}
