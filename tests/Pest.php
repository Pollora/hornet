<?php

declare(strict_types=1);

use Brain\Monkey;
use Pollora\Hornet\Infrastructure\WordPress\HostApplication;
use Pollora\Hornet\Tests\Fixtures\FakeRestResponse;
use Pollora\Hornet\Tests\Fixtures\FakeWpError;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;

// The part of pollora/framework's doctor that the bridge implements: the package does not require the framework
require_once __DIR__.'/Stubs/doctor.php';

// The unit suite has no WordPress: WP_Error is a small stand-in
if (! class_exists('WP_Error', false)) {
    class_alias(FakeWpError::class, 'WP_Error');
}

if (! class_exists('WP_REST_Response', false)) {
    class_alias(FakeRestResponse::class, 'WP_REST_Response');
}

if (! class_exists('WP_User', false)) {
    class_alias(FakeWpUser::class, 'WP_User');
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| No framework to boot. WordPress functions are stubbed with Brain Monkey,
| and Mockery's expectations count as assertions.
|
*/

pest()->beforeEach(function (): void {
    Monkey\setUp();
    Monkey\Functions\stubEscapeFunctions();
})->afterEach(function (): void {
    // Brain Monkey's expectations are checked when Mockery closes: count them as assertions, so a test made only of them is not "risky"
    $expectations = Mockery::getContainer()?->mockery_getExpectationCount() ?? 0;

    Monkey\tearDown();
    // What a service provider said about the host does not carry over to the next test
    HostApplication::set(null);

    expect($expectations)->toBeGreaterThanOrEqual(0);
})->in('Unit');
