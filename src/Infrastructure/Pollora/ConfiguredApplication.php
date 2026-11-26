<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Pollora;

use Closure;
use Pollora\Hornet\Domain\Audit\LaravelApplication;

/**
 * The Laravel application as its configuration describes it. It reads `config()`, never the environment: that is what runs,
 * with or without a cached configuration.
 *
 * @internal
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

    /**
     * Debugbar 4 refuses to run in production, whatever `debugbar.enabled` says, unless `debugbar.force_allow_enable` is on.
     * Debugbar 3 has no such key and runs wherever `debugbar.enabled` says so. Neither key exists when it is not installed.
     */
    public function debugbarForced(): bool
    {
        $enabled = ($this->config)('debugbar.enabled');
        $force = ($this->config)('debugbar.force_allow_enable');

        if ($force === null) {
            return $enabled !== null && filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }

        // Forced: it then runs when its own setting says so, or follows app.debug
        return filter_var($force, FILTER_VALIDATE_BOOLEAN) && ($enabled === null ? $this->debug() : filter_var($enabled, FILTER_VALIDATE_BOOLEAN));
    }

    public function debugbarRestricted(): bool
    {
        // The key comes with pollora/debugbar, which shows the bar to the users who have the capability only
        return ($this->config)('debugbar-pollora.capability') !== null;
    }
}
