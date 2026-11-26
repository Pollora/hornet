<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\RestAllowList;
use Pollora\Hornet\Infrastructure\Config\HardeningSettings;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\RestAccessModule;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

/**
 * The module as WordPress would meet it, serving `$route` to a visitor or to a logged-in user.
 */
function restAccess(?string $route, bool $loggedIn = false, array $extra = [], string $method = 'GET'): RecordingHooks
{
    Functions\when('is_user_logged_in')->justReturn($loggedIn);
    $GLOBALS['wp'] = (object) ['query_vars' => $route === null ? [] : ['rest_route' => $route]];
    $_SERVER['REQUEST_METHOD'] = $method;

    $hooks = new RecordingHooks;
    (new RestAccessModule(RestAllowList::with($extra, false)))->register($hooks);

    return $hooks;
}

afterEach(function (): void {
    unset($GLOBALS['wp'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'], $_GET['_method']);
});

it('opens the index and the default routes, by whole segments and whatever the case', function (string $route, bool $open): void {
    expect(RestAllowList::with([], false)->allows($route))->toBe($open);
})->with([
    ['/', true],
    ['/oembed/1.0/embed', true],
    ['/OEmbed/1.0/Embed', true],
    ['/oembed/1.0/proxy', false],
    ['/oembed/1.0', false],
    ['/contact-form-7/v1/contact-forms/12/feedback', true],
    ['/wc/store/v1/cart', true],
    ['/wc/storefront', false],
    ['/wc/v3/orders', false],
    ['/wp/v2/users', false],
    ['/batch/v1', false],
    ['/headless/v1/site', false],
    ['wc/store/v1/cart', false],
]);

it('opens the public routes of pollora/headless only when it is there', function (): void {
    expect(RestAllowList::with([], true)->allows('/headless/v1/preview/exchange'))->toBeTrue();
});

it('refuses a visitor on a closed route, although WordPress said true to the request without a nonce', function (): void {
    $result = restAccess('/wp/v2/posts')->callback('rest_authentication_errors')(true);

    expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('rest_not_logged_in');
});

it('runs after the checks of WordPress and cannot be undone by a later one', function (): void {
    $hooks = restAccess('/wp/v2/posts');

    expect($hooks->registered[0]['priority'])->toBe(PHP_INT_MAX);
});

it('lets a logged-in user, an open route and an extra prefix through untouched', function (string $route, bool $loggedIn, array $extra): void {
    expect(restAccess($route, $loggedIn, $extra)->callback('rest_authentication_errors')(true))->toBeTrue();
})->with([
    'logged in' => ['/wp/v2/users', true, []],
    'a default' => ['/oembed/1.0/embed/', false, []],
    'added by the owner' => ['/wp/v2/posts/12', false, ['wp/v2/posts']],
]);

it('leaves an error raised before as it is', function (): void {
    $error = new WP_Error('rest_cookie_invalid_nonce');

    expect(restAccess('/wp/v2/posts')->callback('rest_authentication_errors')($error))->toBe($error);
});

it('refuses when there is no route to read', function (?string $route): void {
    expect(restAccess($route)->callback('rest_authentication_errors')(null))->toBeInstanceOf(WP_Error::class);
})->with([null, '']);

it('answers the preflight of a closed route with nothing, and only a real preflight', function (): void {
    $hooks = restAccess('/wp/v2/users', method: 'OPTIONS');

    expect($hooks->callback('rest_authentication_errors')(null))->toBeNull()
        ->and($hooks->callback('rest_pre_dispatch')(null))->toBeInstanceOf(WP_REST_Response::class)
        ->and($hooks->callback('rest_pre_dispatch')(null)->get_data())->toBe([]);
});

it('does not take an OPTIONS that names another method for a preflight', function (string $where): void {
    $hooks = restAccess('/wp/v2/users', method: 'OPTIONS');

    if ($where === 'query') {
        $_GET['_method'] = 'GET';
    } else {
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'GET';
    }

    expect($hooks->callback('rest_authentication_errors')(null))->toBeInstanceOf(WP_Error::class);
})->with(['query', 'header']);

it('takes the routes and the closed namespaces out of the index a visitor reads', function (): void {
    $hooks = restAccess('/', extra: ['wp/v2/posts']);
    $hooks->callback('rest_authentication_errors')(null);
    $response = $hooks->callback('rest_index')(new WP_REST_Response(['authentication' => ['a'], 'namespaces' => ['oembed/1.0', 'wp/v2', 'secret/v1'], 'routes' => ['/x' => []]]));

    expect($response->get_data())->toBe(['authentication' => ['a'], 'namespaces' => ['oembed/1.0', 'wp/v2'], 'routes' => []]);
});

it('leaves internal calls alone: nothing was served to a visitor', function (): void {
    $hooks = restAccess('/', method: 'OPTIONS');
    $index = new WP_REST_Response(['routes' => ['/x' => []]]);

    expect($hooks->callback('rest_index')($index)->get_data())->toBe(['routes' => ['/x' => []]])
        ->and($hooks->callback('rest_pre_dispatch')(null))->toBeNull();
});

it('is off until asked for', function (): void {
    expect(HardeningSettings::fromArray([])->restrictRestApi)->toBeFalse()
        ->and(HardeningSettings::fromArray(['restrict_rest_api' => true, 'rest_api_allow' => ['/wp/v2/posts/']])->restApiAllow)->toBe(['wp/v2/posts']);
});

it('refuses an allow list that would open more than it says', function (mixed $value): void {
    HardeningSettings::fromArray(['rest_api_allow' => $value]);
})->throws(InvalidSettingsException::class)->with([
    'not a list' => ['wp/v2'],
    'everything' => [['']],
    'a slash' => [['/']],
    'the batch route' => [['batch/v1']],
    'the batch route in capitals' => [['Batch']],
    'a pattern' => [['wp/v2/.*']],
    'a dot segment' => [['wp/../v2']],
    'not a string' => [[12]],
]);

it('has the audit say what a visitor over HTTP is told, which its own internal request would not', function (): void {
    $settings = Settings::fromArray(['hardening' => ['restrict_rest_api' => true]]);

    // No REST function is stubbed: asking WordPress at all would end in "unknown"
    expect((new WordPressEnvironment($settings))->usersListedToVisitors())->toBeFalse();
});
