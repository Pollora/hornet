<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;
use Pollora\Hornet\Infrastructure\Pollora\HornetServiceProvider;
use Pollora\Hornet\Infrastructure\WordPress\HostApplication;

/**
 * Where the settings of a Pollora project are published, and read from. Laravel names the configuration of `config/<folder>/<file>.php`
 * `<folder>.<file>`: the provider reads `pollora.hornet`, so the file has to be `config/pollora/hornet.php`. A publication elsewhere
 * leaves every setting unread, and nothing says it: the first trial in a real project found exactly that.
 */
it('publishes the settings where the key it reads comes from', function (): void {
    $app = new class
    {
        public function configPath(string $path = ''): string
        {
            return rtrim('/app/config/'.$path, '/');
        }

        public function bound(string $abstract): bool
        {
            return false;
        }
    };

    (new HornetServiceProvider($app))->boot();
    $paths = ServiceProvider::pathsToPublish(HornetServiceProvider::class, 'hornet-config');

    // The path decides the key: config/pollora/hornet.php is read as pollora.hornet
    expect(array_values($paths))->toBe(['/app/config/pollora/hornet.php'])
        ->and(str_replace(['/app/config/', '.php', '/'], ['', '', '.'], array_values($paths)[0]))->toBe('pollora.hornet');
});

it('says so when the settings are in a file that is not read, and only then', function (): void {
    $config = sys_get_temp_dir().'/hornet-config-'.bin2hex(random_bytes(4));
    mkdir($config.'/pollora', 0o777, true);

    expect(HornetServiceProvider::misplaced($config))->toBeNull();

    touch($config.'/hornet.php');
    expect(HornetServiceProvider::misplaced($config))->toContain('config/pollora/hornet.php');

    touch($config.'/pollora/hornet.php');
    expect(HornetServiceProvider::misplaced($config))->toBeNull();

    unlink($config.'/pollora/hornet.php');
    unlink($config.'/hornet.php');
    rmdir($config.'/pollora');
    rmdir($config);
});

it('starts on plugins_loaded, which it asks for before any provider boots: Pollora answers the REST API while its own boots', function (): void {
    $app = new class
    {
        public ?Closure $booting = null;

        public function booting(Closure $callback): void
        {
            $this->booting = $callback;
        }
    };
    $added = [];
    Functions\when('add_action')->alias(function (string $hook, callable $callback, int $priority = 10) use (&$added): bool {
        $added[] = [$hook, $priority];

        return true;
    });
    Functions\when('did_action')->justReturn(0);

    (new HornetServiceProvider($app))->startOnPluginsLoaded();
    ($app->booting)();

    expect($added)->toBe([['plugins_loaded', 0]]);
});

it('reads the settings of the application, and HORNET_CONFIG only when there is none to define', function (): void {
    $settings = SettingsLoader::load(1, ['journal' => ['enabled' => true], 'sites' => [2 => ['journal' => ['enabled' => false]]]]);

    expect($settings->journal->enabled)->toBeTrue()
        ->and(SettingsLoader::load(2, ['journal' => ['enabled' => true], 'sites' => [2 => ['journal' => ['enabled' => false]]]])->journal->enabled)->toBeFalse()
        ->and(SettingsLoader::load(1, [])->journal->enabled)->toBeFalse();
});

it('tells the audit it runs on Laravel as soon as it is registered, console included', function (): void {
    // A cached configuration: the provider merges nothing, and the file that reads env() is not loaded
    $app = new class implements CachesConfiguration
    {
        public function getCachedConfigPath(): string
        {
            return '';
        }

        public function getCachedServicesPath(): string
        {
            return '';
        }

        public function make(string $abstract): object
        {
            return new class
            {
                public function get(string $key, mixed $default = null): mixed
                {
                    return $key === 'app.debug' ? true : $default;
                }

                public function set(string $key, mixed $value): void {}
            };
        }

        public function booting(Closure $callback): void {}

        public function configurationIsCached(): bool
        {
            return true;
        }

        public function singleton(string $abstract, ?Closure $concrete = null): void {}

        public function tag(array $abstracts, string $tag): void {}
    };

    expect(HostApplication::laravel())->toBeNull();

    (new HornetServiceProvider($app))->register();

    expect(HostApplication::laravel()?->debug())->toBeTrue();
});
