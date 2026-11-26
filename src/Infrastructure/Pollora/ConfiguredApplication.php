<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Pollora;

use Closure;
use Pollora\Hornet\Domain\Audit\LaravelApplication;

/**
 * The Laravel application as its configuration describes it. It reads `config()`, never the environment: that is what runs,
 * with or without a cached configuration.
 */
final readonly class ConfiguredApplication implements LaravelApplication
{
    /**
     * @param  Closure(string): mixed  $config  the value of a configuration key, null when there is none
     */
    public function __construct(private Closure $config) {}

    public function debug(): bool
    {
        return filter_var(($this->config)('app.debug'), FILTER_VALIDATE_BOOLEAN);
    }

    public function hasKey(): bool
    {
        $key = ($this->config)('app.key');

        return is_string($key) && trim($key) !== '';
    }

    public function debugbarForced(): bool
    {
        // The key only exists when Debugbar is installed; null means it follows app.debug
        $enabled = ($this->config)('debugbar.enabled');

        return $enabled !== null && filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }
}
