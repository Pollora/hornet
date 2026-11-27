<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\CachesConfiguration;

/**
 * The part of a Laravel application that the service provider asks, without Laravel: a container of what was bound, the
 * configuration, and what was registered, kept for the test to read. Its configuration is cached: the file that reads
 * `env()`, which needs more of Laravel than the package does, is not loaded.
 */
final class FakeApplication implements CachesConfiguration
{
    public function configurationIsCached(): bool
    {
        return true;
    }

    public function getCachedConfigPath(): string
    {
        return '';
    }

    public function getCachedServicesPath(): string
    {
        return '';
    }

    /** @var array<string, mixed> */
    public array $bindings = [];

    /** @var array<string, Closure> */
    public array $singletons = [];

    /** @var array<string, list<string>> */
    public array $tags = [];

    /** @var list<Closure> */
    public array $booting = [];

    /** @var array<string, mixed> */
    public array $config = [];

    public string $configPath = '/nowhere/config';

    public function bound(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->singletons[$abstract]);
    }

    public function make(string $abstract): mixed
    {
        if ($abstract === 'config') {
            return new class($this)
            {
                public function __construct(private readonly FakeApplication $app) {}

                public function get(string $key, mixed $default = null): mixed
                {
                    return $this->app->config[$key] ?? $default;
                }

                public function set(string $key, mixed $value): void
                {
                    $this->app->config[$key] = $value;
                }
            };
        }

        if (isset($this->singletons[$abstract])) {
            return $this->singletons[$abstract]();
        }

        return $this->bindings[$abstract] ?? throw new \LogicException('Nothing is bound to '.$abstract);
    }

    public function singleton(string $abstract, ?Closure $concrete = null): void
    {
        $this->singletons[$abstract] = $concrete ?? static fn (): null => null;
    }

    /**
     * @param  list<string>  $abstracts
     */
    public function tag(array $abstracts, string $tag): void
    {
        $this->tags[$tag] = [...($this->tags[$tag] ?? []), ...$abstracts];
    }

    public function booting(Closure $callback): void
    {
        $this->booting[] = $callback;
    }

    public function configPath(string $path = ''): string
    {
        return rtrim($this->configPath.'/'.$path, '/');
    }
}
