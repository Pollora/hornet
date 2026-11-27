<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Password\BreachCheck;
use Pollora\Hornet\Domain\Http\ContentSecurityPolicy;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Infrastructure\Config\HardeningSettings;
use Pollora\Hornet\Infrastructure\Config\LoginSettings;
use Pollora\Hornet\Infrastructure\WordPress\HardeningModule;
use Pollora\Hornet\Infrastructure\WordPress\HeadersModule;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\PasswordModule;
use Pollora\Hornet\Infrastructure\WordPress\RestAccessModule;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\InMemoryAttemptStore;
use Pollora\Hornet\Tests\Fixtures\MutationWordPressHooks;
use Pollora\Hornet\Tests\Fixtures\SentHeaders;

afterEach(function (): void {
    unset($GLOBALS['wp'], $_SERVER['REQUEST_METHOD']);
});

/** @param array<string, mixed> $login */
function mutWpThrottle(InMemoryAttemptStore $store, FakeClock $clock, array $login = [], ?ClientIp $client = new ClientIp('203.0.113.7', '203.0.113.7')): LoginThrottleModule
{
    $settings = LoginSettings::fromArray(['throttle' => true, 'max_attempts' => 3, ...$login]);

    return new LoginThrottleModule($settings, $store, $clock, static fn (): ?ClientIp => $client, static fn (): null => null, 'salt');
}

it('rounds the wait up to the next whole minute', function (): void {
    $store = new InMemoryAttemptStore;
    $clock = new FakeClock;
    $module = mutWpThrottle($store, $clock);

    foreach (range(1, 3) as $ignored) {
        $module->failed();
    }

    // 850 seconds are left: 14 minutes and a bit, which is 15 for a person
    $clock->advance('PT50S');

    expect($module->gate('user')->get_error_message())->toBe('<strong>Error:</strong> Too many failed login attempts. Try again in 15 minute(s).');
});

it('keeps the count for the longer of the window and the lock, and one day more', function (array $login, int $failures, int $kept): void {
    $store = new InMemoryAttemptStore;
    $module = mutWpThrottle($store, new FakeClock, $login);

    foreach (range(1, $failures) as $ignored) {
        $module->failed();
    }

    expect(array_values($store->keptFor))->toBe([$kept]);
})->with([
    'a count, not locked: the window' => [['window' => 900, 'lockout' => 3600], 1, 900 + 86400],
    'a lock longer than the window' => [['window' => 900, 'lockout' => 3600], 3, 3600 + 86400],
    'a window longer than the lock' => [['window' => 3600, 'lockout' => 600], 3, 3600 + 86400],
]);

it('writes down a success for one day, under the hash of the address', function (): void {
    $store = new InMemoryAttemptStore;
    $module = mutWpThrottle($store, new FakeClock);
    $module->succeeded();

    expect($store->keptFor)->toBe([(new ClientIp('203.0.113.7', '203.0.113.7'))->key('salt') => 86400]);
});

it('writes nothing for a success of nobody', function (): void {
    $store = new InMemoryAttemptStore;
    mutWpThrottle($store, new FakeClock, [], null)->succeeded();

    expect($store->records)->toBe([]);
});

it('does not count the refusal of an application password as a guess, even before any lock', function (): void {
    $store = new InMemoryAttemptStore;
    mutWpThrottle($store, new FakeClock)->applicationPasswordFailed(new WP_Error('hornet_locked'));

    expect($store->records)->toBe([]);
});

it('adds nothing to an application password of a request with no visitor', function (): void {
    $error = new WP_Error;
    mutWpThrottle(new InMemoryAttemptStore, new FakeClock, [], null)->gateApplicationPassword($error);

    expect($error->added)->toBe([]);
});

it('adds nothing to what is not an error, locked or not', function (): void {
    $module = mutWpThrottle(new InMemoryAttemptStore, new FakeClock);

    foreach (range(1, 3) as $ignored) {
        $module->failed();
    }

    $module->gateApplicationPassword(null);

    expect($module->gate('user'))->toBeInstanceOf(WP_Error::class);
});

/** The module of a closed REST API, with who asks and for what. */
function mutWpRest(mixed $wp, bool &$loggedIn, string $method = 'GET'): RestAccessModule
{
    Functions\when('is_user_logged_in')->alias(function () use (&$loggedIn): bool {
        return $loggedIn;
    });
    $GLOBALS['wp'] = $wp;
    $_SERVER['REQUEST_METHOD'] = $method;

    return new RestAccessModule(HardeningSettings::fromArray([])->restAllowList(false));
}

function mutWpRoute(string $route): object
{
    return (object) ['query_vars' => ['rest_route' => $route]];
}

it('answers a visitor with a 401', function (): void {
    $loggedIn = false;
    $result = mutWpRest(mutWpRoute('/wp/v2/posts'), $loggedIn)->guard(null);

    expect($result->get_error_data())->toBe(['status' => 401]);
});

it('forgets the visitor of a request before, when the next one is logged in', function (): void {
    $loggedIn = false;
    $module = mutWpRest(mutWpRoute('/wp/v2/users'), $loggedIn, 'OPTIONS');
    $module->guard(null);
    $loggedIn = true;
    $module->guard(null);
    $index = new WP_REST_Response(['routes' => ['/x' => []]]);

    expect($module->index($index)->get_data())->toBe(['routes' => ['/x' => []]])
        ->and($module->answerPreflight(null))->toBeNull();
});

it('leaves what is not a response in the index of a visitor', function (): void {
    $loggedIn = false;
    $module = mutWpRest(mutWpRoute('/'), $loggedIn);
    $module->guard(null);
    $error = new WP_Error('x');

    expect($module->index($error))->toBe($error);
});

it('keeps the list of namespaces a list', function (): void {
    $loggedIn = false;
    $module = mutWpRest(mutWpRoute('/'), $loggedIn);
    $module->guard(null);
    $data = $module->index(new WP_REST_Response(['namespaces' => ['secret/v1', 'oembed/1.0']]))->get_data();

    expect($data['namespaces'])->toBe(['oembed/1.0']);
});

it('reads a route that is only slashes as the index', function (): void {
    $loggedIn = false;

    expect(mutWpRest(mutWpRoute('//'), $loggedIn)->guard(null))->toBeNull();
});

it('takes the preflight whatever the case of the method', function (): void {
    $loggedIn = false;
    $module = mutWpRest(mutWpRoute('/wp/v2/users'), $loggedIn, 'options');

    expect($module->guard(null))->toBeNull()
        ->and($module->answerPreflight(null))->toBeInstanceOf(WP_REST_Response::class);
});

it('removes the user routes among endpoints whose names are numbers', function (): void {
    Functions\when('is_user_logged_in')->justReturn(false);

    expect(HardeningModule::withoutUserRoutes([0 => 'zero', '/wp/v2/users' => 'users', '/wp/v2/users/me' => 'me', '/wp/v2/posts' => 'posts']))
        ->toBe([0 => 'zero', '/wp/v2/posts' => 'posts']);
});

it('refuses a password seen exactly as many times as the threshold', function (int $seen, int $refusals): void {
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse(200, null, PASSWORD_SUFFIX.':'.$seen)];
    $errors = new WP_Error;

    (new PasswordModule(new BreachCheck($http), 3))->onProfile($errors, true, (object) ['user_pass' => 'password']);

    expect($errors->added)->toHaveCount($refusals);
})->with([[2, 0], [3, 1], [4, 1]]);

it('adds the policy of the headers to the one of WordPress instead of taking its place', function (): void {
    Functions\when('is_ssl')->justReturn(true);
    $headers = new SecurityHeaders(true, csp: new ContentSecurityPolicy(false, "default-src 'self'", admin: true));

    MutationWordPressHooks::of(new HeadersModule($headers))->fire('admin_init');

    $replace = [];

    foreach (SentHeaders::$sent as [$line, $replaces]) {
        $replace[explode(':', $line)[0]] = $replaces;
    }

    expect($replace['X-Content-Type-Options'])->toBeTrue()
        ->and($replace['Content-Security-Policy-Report-Only'])->toBeFalse();
});
