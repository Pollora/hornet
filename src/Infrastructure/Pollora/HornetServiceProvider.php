<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Pollora;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Exceptions\HookServiceException;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;
use Pollora\Hornet\Infrastructure\Login\ClientAddress;
use Pollora\Hornet\Infrastructure\WordPress\AuditorFactory;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\HostApplication;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Throwable;

/**
 * Loads the package inside a Pollora (Laravel) project: Laravel discovers this provider from composer.json.
 *
 * Settings live under `pollora.hornet`: the file is `config/pollora/hornet.php` (published with the `hornet-config` tag). Laravel reads
 * a folder inside `config/` as a prefix, so that is the only place that is read; a `config/hornet.php` is reported, never ignored in silence.
 *
 * @internal
 */
final class HornetServiceProvider extends ServiceProvider
{
    private const string ACTION = 'Pollora\\Hook\\Domain\\Contract\\Action';

    private const string FILTER = 'Pollora\\Hook\\Domain\\Contract\\Filter';

    private const string KERNEL = Kernel::class;

    private const string DOCTOR_CHECK = 'Pollora\\Doctor\\Domain\\Contracts\\CheckInterface';

    /** The tag of DoctorServiceProvider::CHECKS_TAG: not read from the class, which may not be installed. */
    private const string DOCTOR_TAG = 'pollora.doctor.checks';

    private bool $started = false;

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3).'/config/hornet.php', 'pollora.hornet');

        // Said as soon as the provider is registered: the console (pollora:doctor, WP-CLI) builds an audit without ever starting
        HostApplication::set(new ConfiguredApplication(fn (string $key): mixed => $this->configuration($key)));

        $this->startOnPluginsLoaded();

        if (interface_exists(self::DOCTOR_CHECK)) {
            $this->app->singleton(DoctorSecurityCheck::class, fn (): DoctorSecurityCheck => new DoctorSecurityCheck(fn (): Report => $this->audit()));
            $this->app->tag([DoctorSecurityCheck::class], self::DOCTOR_TAG);
        }
    }

    /**
     * Pollora runs the WordPress request (`wp()`) while its own provider boots, before this package's does: WordPress answers the REST
     * API there and stops, and `init` is long gone. Hornet therefore starts on `plugins_loaded`, asked for before any provider boots.
     */
    public function startOnPluginsLoaded(): void
    {
        $this->app->booting(function (): void {
            if (function_exists('add_action') && function_exists('did_action') && ! did_action('plugins_loaded')) {
                add_action('plugins_loaded', $this->start(...), 0);
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([dirname(__DIR__, 3).'/config/hornet.php' => $this->app->configPath('pollora/hornet.php')], 'hornet-config');

        // Already started on `plugins_loaded`; here for a WordPress that was loaded before the providers, or one that never loads
        if (! function_exists('did_action') || did_action('plugins_loaded')) {
            $this->start();
        }
    }

    /**
     * Attaches Hornet to WordPress, once.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (! $this->app->bound(self::ACTION) || ! $this->app->bound(self::FILTER)) {
            return;
        }

        $action = $this->app->make(self::ACTION);
        $filter = $this->app->make(self::FILTER);

        if (! is_object($action) || ! is_object($filter)) {
            throw HookServiceException::notAnObject();
        }

        $this->started = true;
        $hooks = new PolloraHooks($action, $filter);

        $misplaced = self::misplaced($this->app->configPath());

        if ($misplaced !== null) {
            Bootstrap::boot(null, $hooks, new WordPressEnvironment, $misplaced);

            return;
        }

        try {
            $settings = $this->settings();
        } catch (InvalidArgumentException $invalidArgumentException) {
            Bootstrap::boot(null, $hooks, new WordPressEnvironment, $invalidArgumentException->getMessage());

            return;
        }

        Bootstrap::boot($settings, $hooks, new WordPressEnvironment($settings), null, $this->client($settings));

        if ($settings->headers->isActive()) {
            $this->sendHeadersFromLaravel($settings->headers);
        }
    }

    /**
     * What to say when the settings are in a file that is not read: `config/hornet.php` is loaded by Laravel as `hornet`, and not as
     * `pollora.hornet`, so everything written in it would do nothing while looking done. Null when there is nothing to say.
     */
    public static function misplaced(string $configPath): ?string
    {
        if (! is_file($configPath.'/hornet.php') || is_file($configPath.'/pollora/hornet.php')) {
            return null;
        }

        return 'config/hornet.php is not read: move it to config/pollora/hornet.php.';
    }

    /**
     * The public site is a Laravel response on Pollora: the WordPress hooks do not reach it, a global middleware does.
     */
    private function sendHeadersFromLaravel(SecurityHeaders $headers): void
    {
        $this->app->singleton(SecurityHeadersMiddleware::class, static fn (): SecurityHeadersMiddleware => new SecurityHeadersMiddleware($headers));
        $kernel = $this->app->make(self::KERNEL);

        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(SecurityHeadersMiddleware::class);
        }
    }

    /**
     * Who is asking, as Laravel tells it: its request already knows which proxies the application trusts, so Hornet does not keep a second list.
     *
     * @return Closure(): ?ClientIp
     */
    private function client(Settings $settings): Closure
    {
        return function () use ($settings): ?ClientIp {
            $remote = $_SERVER['REMOTE_ADDR'] ?? null;

            try {
                $request = $this->app->make('request');

                if (! is_object($request) || ! method_exists($request, 'ip')) {
                    return ClientAddress::fromServer($_SERVER, $settings->network);
                }

                $address = $request->ip();
            } catch (Throwable) {
                return ClientAddress::fromServer($_SERVER, $settings->network);
            }

            return is_string($address) && is_string($remote) && filter_var($address, FILTER_VALIDATE_IP) !== false ? new ClientIp($address, $remote) : null;
        };
    }

    private function settings(): Settings
    {
        $config = $this->configuration('pollora.hornet') ?? [];

        if (! is_array($config)) {
            throw InvalidSettingsException::invalid('pollora.hornet', 'an array');
        }

        return SettingsLoader::load(function_exists('get_current_blog_id') ? get_current_blog_id() : 1, $config);
    }

    /**
     * @throws InvalidSettingsException when there is no configuration to read: it is not taken for an empty one
     */
    private function configuration(string $key): mixed
    {
        $repository = $this->app->make('config');

        if (! is_object($repository) || ! method_exists($repository, 'get')) {
            throw InvalidSettingsException::invalid('config', 'the configuration repository of the application');
        }

        return $repository->get($key);
    }

    private function audit(): Report
    {
        $settings = $this->settings();

        return AuditorFactory::forWordPress($settings)->run(new WordPressEnvironment($settings));
    }
}
