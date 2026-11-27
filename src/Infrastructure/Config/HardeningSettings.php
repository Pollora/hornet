<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\RestAllowList;

/**
 * The `hardening` settings: what Hornet closes on the site. Everything is off until it is asked for.
 *
 * `isActive()` speaks of the pieces of HardeningModule only: the closed REST API is a module of its own.
 *
 * @internal
 */
final readonly class HardeningSettings
{
    /**
     * What visitors need on an ordinary site, kept open when the REST API is closed: the embeds of this site by others, the forms of
     * Contact Form 7, and the shop of the WooCommerce cart and checkout blocks. A prefix of a plugin that is not installed opens nothing.
     */
    public const array REST_DEFAULTS = ['oembed/1.0/embed', 'contact-form-7/v1/contact-forms', 'wc/store'];

    /** The public routes of pollora/headless: its front end has no WordPress login, even to trade a preview token. */
    public const string REST_HEADLESS = 'headless/v1';

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

    /**
     * The routes visitors keep: the defaults, those of pollora/headless when it is there, and what the owner added.
     */
    public function restAllowList(bool $headless): RestAllowList
    {
        return new RestAllowList([...self::REST_DEFAULTS, ...($headless ? [self::REST_HEADLESS] : []), ...$this->restApiAllow]);
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
            ($value['xmlrpc'] ?? false) === true,
            ($value['user_enumeration'] ?? false) === true,
            ($value['hide_version'] ?? false) === true,
            ($value['file_editor'] ?? false) === true,
            ($value['restrict_rest_api'] ?? false) === true,
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
