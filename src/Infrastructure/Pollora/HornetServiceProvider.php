<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Pollora;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;

/**
 * Loads the package inside a Pollora (Laravel) project: Laravel discovers this provider from composer.json.
 *
 * Settings live under `pollora.hornet` (see config/hornet.php, published with the `hornet-config` tag).
 */
final class HornetServiceProvider extends ServiceProvider
{
    private const string ACTION = 'Pollora\\Hook\\Domain\\Contract\\Action';

    private const string FILTER = 'Pollora\\Hook\\Domain\\Contract\\Filter';

    private const string KERNEL = Kernel::class;

    private const string DOCTOR_CHECK = 'Pollora\\Doctor\\Domain\\Contracts\\CheckInterface';

    /** The tag of DoctorServiceProvider::CHECKS_TAG: not read from the class, which may not be installed. */
    private const string DOCTOR_TAG = 'pollora.doctor.checks';

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3).'/config/hornet.php', 'pollora.hornet');

        if (interface_exists(self::DOCTOR_CHECK)) {
            $this->app->singleton(DoctorSecurityCheck::class, fn (): DoctorSecurityCheck => new DoctorSecurityCheck(fn (): Report => $this->audit()));
            $this->app->tag([DoctorSecurityCheck::class], self::DOCTOR_TAG);
        }
    }

    public function boot(): void
    {
        $this->publishes([dirname(__DIR__, 3).'/config/hornet.php' => $this->app->configPath('hornet.php')], 'hornet-config');

        if (! $this->app->bound(self::ACTION) || ! $this->app->bound(self::FILTER)) {
            return;
        }

        $hooks = new PolloraHooks($this->app->make(self::ACTION), $this->app->make(self::FILTER));

        try {
            $settings = $this->settings();
        } catch (InvalidArgumentException $invalidArgumentException) {
            Bootstrap::boot(null, $hooks, new WordPressEnvironment, $invalidArgumentException->getMessage());

            return;
        }

        Bootstrap::boot($settings, $hooks, new WordPressEnvironment);

        if ($settings->headers->isActive()) {
            $this->sendHeadersFromLaravel($settings->headers);
        }
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

    private function settings(): Settings
    {
        /** @var array<mixed> $config */
        $config = $this->app->make('config')->get('pollora.hornet', []);

        return Settings::fromArray($config);
    }

    private function audit(): Report
    {
        $settings = $this->settings();

        return (new Auditor(DefaultChecks::all(), $settings->disabledChecks, $settings->ignored))->run(new WordPressEnvironment);
    }
}
