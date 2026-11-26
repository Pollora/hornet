<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * The `hardening` settings: what Hornet closes on the site. Everything is off until it is asked for.
 *
 * `isActive()` speaks of the pieces of HardeningModule only: the closed REST API is a module of its own.
 *
 * @internal
 */
final readonly class HardeningSettings
{
    private const array KEYS = ['xmlrpc', 'user_enumeration', 'hide_version', 'file_editor', 'restrict_rest_api', 'rest_api_allow'];

    /**
     * @param  list<string>  $restApiAllow  route prefixes visitors keep, without leading or trailing slash
     */
    private function __construct(
        public bool $xmlRpc,
        public bool $userEnumeration,
        public bool $hideVersion,
        public bool $fileEditor,
        public bool $restrictRestApi = false,
        public array $restApiAllow = [],
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

            if ($key !== 'rest_api_allow' && ! is_bool($setting)) {
                throw InvalidSettingsException::invalid('hardening.'.$key, 'true or false');
            }
        }

        return new self(
            $value['xmlrpc'] ?? false,
            $value['user_enumeration'] ?? false,
            $value['hide_version'] ?? false,
            $value['file_editor'] ?? false,
            $value['restrict_rest_api'] ?? false,
            self::prefixes($value['rest_api_allow'] ?? []),
        );
    }

    /**
     * Route prefixes of whole segments. An empty one would open everything, and the batch route runs other routes without asking who
     * is calling: both are refused.
     *
     * @return list<string>
     *
     * @throws InvalidSettingsException
     */
    private static function prefixes(mixed $value): array
    {
        $expected = "a list of REST route prefixes such as 'wp/v2/posts' (the batch route cannot be opened)";

        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidSettingsException::invalid('hardening.rest_api_allow', $expected);
        }

        $prefixes = [];

        foreach ($value as $prefix) {
            $prefix = is_string($prefix) ? trim($prefix, '/') : '';

            if (preg_match('#\A[A-Za-z0-9_-][A-Za-z0-9_.-]*(/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z#', $prefix) !== 1 || preg_match('#\Abatch(/|\z)#i', $prefix) === 1) {
                throw InvalidSettingsException::invalid('hardening.rest_api_allow', $expected);
            }

            $prefixes[] = $prefix;
        }

        return $prefixes;
    }
}
