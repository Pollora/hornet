<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Http;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * Strict-Transport-Security. It is easy to get wrong and hard to take back: a browser keeps the rule for `max-age`
 * seconds, so a site that cannot serve HTTPS for a year is out for a year. Hence the guard rails: a short minimum, and
 * `preload` only with the conditions the preload list asks for.
 */
final readonly class Hsts
{
    private const int MIN_AGE = 300;

    private const int PRELOAD_AGE = 31536000;

    /**
     * @throws InvalidSettingsException
     */
    public function __construct(
        public int $maxAge,
        public bool $includeSubDomains = false,
        public bool $preload = false,
    ) {
        if ($maxAge < self::MIN_AGE || $maxAge > 63072000) {
            throw InvalidSettingsException::invalid('headers.hsts.max_age', 'a number of seconds from 300 to 63072000');
        }

        if ($preload && (! $includeSubDomains || $maxAge < self::PRELOAD_AGE)) {
            throw InvalidSettingsException::invalid('headers.hsts.preload', 'used with include_subdomains and a max_age of a year (31536000) or more: the preload list refuses less, and a preloaded site cannot go back easily');
        }
    }

    public function value(): string
    {
        return 'max-age='.$this->maxAge.($this->includeSubDomains ? '; includeSubDomains' : '').($this->preload ? '; preload' : '');
    }
}
