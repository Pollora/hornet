<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\ContentSecurityPolicy;
use Pollora\Hornet\Domain\Http\Hsts;
use Pollora\Hornet\Domain\Http\SecurityHeaders;

/**
 * Reads the `headers` settings into the headers to send. Nothing is sent until a header is asked for.
 *
 * @internal
 */
final class HeaderSettings
{
    private const array KEYS = ['content_type_options', 'referrer_policy', 'permissions_policy', 'hsts', 'csp'];

    /**
     * @throws InvalidSettingsException
     */
    public static function fromArray(mixed $value): SecurityHeaders
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('headers', 'an array');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'headers');
            }
        }

        return new SecurityHeaders(
            self::flag($value, 'content_type_options'),
            self::text($value, 'referrer_policy'),
            self::text($value, 'permissions_policy'),
            array_key_exists('hsts', $value) ? self::hsts($value['hsts']) : null,
            array_key_exists('csp', $value) ? self::csp($value['csp']) : null,
        );
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function flag(array $value, string $key): bool
    {
        $setting = $value[$key] ?? false;

        return is_bool($setting) ? $setting : throw InvalidSettingsException::invalid('headers.'.$key, 'true or false');
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function text(array $value, string $key): ?string
    {
        $setting = $value[$key] ?? null;

        return match (true) {
            $setting === null => null,
            is_string($setting) => $setting,
            default => throw InvalidSettingsException::invalid('headers.'.$key, 'a string'),
        };
    }

    private static function hsts(mixed $value): Hsts
    {
        if (! is_array($value) || ! is_int($value['max_age'] ?? null)) {
            throw InvalidSettingsException::invalid('headers.hsts', "an array with an integer 'max_age'");
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, ['max_age', 'include_subdomains', 'preload'], true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'headers.hsts');
            }
        }

        $subdomains = $value['include_subdomains'] ?? false;
        $preload = $value['preload'] ?? false;

        if (! is_bool($subdomains) || ! is_bool($preload)) {
            throw InvalidSettingsException::invalid('headers.hsts', 'include_subdomains and preload as true or false');
        }

        return new Hsts($value['max_age'], $subdomains, $preload);
    }

    private static function csp(mixed $value): ContentSecurityPolicy
    {
        if (! is_array($value) || ! is_string($value['policy'] ?? null)) {
            throw InvalidSettingsException::invalid('headers.csp', "an array with a 'policy' string");
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, ['mode', 'policy', 'report_uri', 'admin'], true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'headers.csp');
            }
        }

        $mode = $value['mode'] ?? 'report-only';
        $admin = $value['admin'] ?? false;
        $reportUri = $value['report_uri'] ?? null;

        if (! in_array($mode, ['report-only', 'enforce'], true) || ! is_bool($admin) || ($reportUri !== null && ! is_string($reportUri))) {
            throw InvalidSettingsException::invalid('headers.csp', "a 'mode' of report-only or enforce, 'admin' as true or false, and a 'report_uri' string");
        }

        return new ContentSecurityPolicy($mode === 'enforce', $value['policy'], $reportUri, $admin);
    }
}
