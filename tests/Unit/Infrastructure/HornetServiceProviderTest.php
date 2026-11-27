<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Contracts\Http\Kernel;
use Pollora\Doctor\Domain\Enums\RunContext;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Pollora\DoctorSecurityCheck;
use Pollora\Hornet\Infrastructure\Pollora\HornetServiceProvider;
use Pollora\Hornet\Infrastructure\Pollora\SecurityHeadersMiddleware;
use Pollora\Hornet\Tests\Fixtures\AdminSite;
use Pollora\Hornet\Tests\Fixtures\FakeApplication;
use Pollora\Hornet\Tests\Fixtures\FakeSite;

const HOOK_ACTION = 'Pollora\\Hook\\Domain\\Contract\\Action';

const HOOK_FILTER = 'Pollora\\Hook\\Domain\\Contract\\Filter';

/**
 * A Pollora project with its hook services, a Laravel kernel and the settings given.
 */
function polloraApp(array $settings = []): FakeApplication
{
    $hooks = static fn (): object => new class
    {
        /** @var array<string, list<callable>> */
        public array $added = [];

        public function add(string $hook, callable $callback, int $priority, int $arguments): void
        {
            $this->added[$hook][] = $callback;
        }
    };
    $app = new FakeApplication;
    $app->config['pollora.hornet'] = $settings;
    $app->bindings[HOOK_ACTION] = $hooks();
    $app->bindings[HOOK_FILTER] = $hooks();
    $app->bindings[Kernel::class] = new class
    {
        /** @var list<string> */
        public array $middleware = [];

        public function pushMiddleware(string $middleware): void
        {
            $this->middleware[] = $middleware;
        }
    };

    return $app;
}

function attached(FakeApplication $app): array
{
    return [...array_keys($app->bindings[HOOK_ACTION]->added), ...array_keys($app->bindings[HOOK_FILTER]->added)];
}

beforeEach(function (): void {
    FakeSite::install();
    Functions\when('is_admin')->justReturn(false);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_doing_cron')->justReturn(false);
    Functions\when('is_main_site')->justReturn(true);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('wp_salt')->justReturn('salt');
    Functions\when('add_action')->justReturn(true);
    Functions\when('did_action')->justReturn(1);
});

it('registers the check of pollora:doctor, and leaves a cached configuration alone', function (): void {
    $app = new FakeApplication;
    (new HornetServiceProvider($app))->register();

    expect($app->config)->toBe([])
        ->and($app->tags)->toBe(['pollora.doctor.checks' => [DoctorSecurityCheck::class]])
        ->and($app->make(DoctorSecurityCheck::class))->toBeInstanceOf(DoctorSecurityCheck::class)
        ->and($app->booting)->toHaveCount(1);
});

it('audits the site with the settings of the application when pollora:doctor asks', function (): void {
    AdminSite::stubScanner();
    $app = new FakeApplication;
    $app->config['pollora.hornet'] = ['disabled_checks' => ['nothing-like-this']];
    (new HornetServiceProvider($app))->register();
    $check = $app->make(DoctorSecurityCheck::class);

    // The settings are read when the check runs: a wrong one is said then, not swallowed
    expect(fn () => $check->run(RunContext::Console))->toThrow(InvalidArgumentException::class, 'nothing-like-this');
});

it('attaches Hornet through the hook services of Pollora, once', function (): void {
    $app = polloraApp(['hardening' => ['xmlrpc' => true]]);
    $provider = new HornetServiceProvider($app);
    $provider->boot();
    $first = $app->bindings[HOOK_ACTION]->added + $app->bindings[HOOK_FILTER]->added;
    $provider->start();

    expect(attached($app))->toContain('site_status_tests', 'xmlrpc_enabled')
        ->and($app->bindings[HOOK_ACTION]->added + $app->bindings[HOOK_FILTER]->added)->toBe($first);
});

it('waits for the hook services, and starts when they are there', function (): void {
    $app = polloraApp();
    $action = $app->bindings[HOOK_ACTION];
    unset($app->bindings[HOOK_ACTION]);
    $provider = new HornetServiceProvider($app);
    $provider->start();

    expect($app->bindings[HOOK_FILTER]->added)->toBe([]);

    $app->bindings[HOOK_ACTION] = $action;
    $provider->start();

    expect(attached($app))->toContain('site_status_tests');
});

it('does not start at boot while plugins_loaded is still to come', function (): void {
    Functions\when('did_action')->justReturn(0);
    $app = polloraApp();
    (new HornetServiceProvider($app))->boot();

    expect(attached($app))->toBe([]);
});

it('asks for nothing before boot when plugins are already loaded', function (): void {
    $added = [];
    Functions\when('add_action')->alias(function (string $hook) use (&$added): bool {
        $added[] = $hook;

        return true;
    });
    $app = polloraApp();
    (new HornetServiceProvider($app))->startOnPluginsLoaded();
    $app->booting[0]();

    expect($added)->toBe([]);
});

it('says on Site Health that its settings cannot be read, and starts nothing else', function (string $why, array $settings): void {
    $app = polloraApp($settings);
    $config = sys_get_temp_dir().'/hornet-provider-'.bin2hex(random_bytes(4));
    mkdir($config);
    $app->configPath = $config;

    if ($why === 'misplaced') {
        touch($config.'/hornet.php');
    }

    (new HornetServiceProvider($app))->start();
    $attached = $app->bindings[HOOK_FILTER]->added;

    expect(array_keys($attached))->toBe(['site_status_tests'])
        ->and($app->bindings[HOOK_ACTION]->added)->not->toHaveKey('wp_login');

    $tests = $attached['site_status_tests'][0](['direct' => []]);
    $result = $tests['direct'][array_key_first($tests['direct'])]['test']();

    expect($result['description'])->toContain($why === 'misplaced' ? 'config/pollora/hornet.php' : 'enable_everything');

    if (is_file($config.'/hornet.php')) {
        unlink($config.'/hornet.php');
    }

    rmdir($config);
})->with([
    ['misplaced', []],
    ['invalid', ['enable_everything' => true]],
]);

it('sends the headers from Laravel when some are set, and not otherwise', function (array $settings, array $middleware): void {
    $app = polloraApp($settings);
    (new HornetServiceProvider($app))->start();

    expect($app->bindings[Kernel::class]->middleware)->toBe($middleware)
        ->and(isset($app->singletons[SecurityHeadersMiddleware::class]))->toBe($middleware !== []);

    if ($middleware !== []) {
        expect($app->make(SecurityHeadersMiddleware::class))->toBeInstanceOf(SecurityHeadersMiddleware::class);
    }
})->with([
    'headers' => [['headers' => ['content_type_options' => true]], [SecurityHeadersMiddleware::class]],
    'none' => [[], []],
]);

it('does not fail on a kernel that takes no middleware', function (): void {
    $app = polloraApp(['headers' => ['content_type_options' => true]]);
    $app->bindings[Kernel::class] = new stdClass;
    (new HornetServiceProvider($app))->start();

    expect(attached($app))->toContain('wp_headers');
});

describe('who is asking', function (): void {
    function client(FakeApplication $app): ?ClientIp
    {
        $provider = new HornetServiceProvider($app);
        $method = new ReflectionMethod($provider, 'client');

        return $method->invoke($provider, Settings::fromArray([]))();
    }

    function laravelRequest(mixed $ip): object
    {
        return new class($ip)
        {
            public function __construct(private readonly mixed $address) {}

            public function ip(): mixed
            {
                return $this->address;
            }
        };
    }

    afterEach(function (): void {
        unset($_SERVER['REMOTE_ADDR']);
    });

    it('is what the request of Laravel says, which knows the trusted proxies', function (): void {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $app = new FakeApplication;
        $app->bindings['request'] = laravelRequest('203.0.113.4');

        expect(client($app))->toEqual(new ClientIp('203.0.113.4', '10.0.0.2'));
    });

    it('is nobody when the request or the connection has no usable address', function (mixed $address, mixed $remote): void {
        if ($remote !== null) {
            $_SERVER['REMOTE_ADDR'] = $remote;
        }

        $app = new FakeApplication;
        $app->bindings['request'] = laravelRequest($address);

        expect(client($app))->toBeNull();
    })->with([[null, '10.0.0.2'], ['not an address', '10.0.0.2'], ['203.0.113.4', null]]);

    it('falls back on the server variables when there is no request', function (): void {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';

        expect(client(new FakeApplication)?->address)->toBe('198.51.100.9');
    });
});
