<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Pollora\Hornet\Infrastructure\WordPress\HostApplication;
use Pollora\Hornet\Tests\Fixtures\FakeApplicationPasswords;
use Pollora\Hornet\Tests\Fixtures\FakeRestResponse;
use Pollora\Hornet\Tests\Fixtures\FakeSessionTokens;
use Pollora\Hornet\Tests\Fixtures\FakeTheme;
use Pollora\Hornet\Tests\Fixtures\FakeWpError;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;
use Pollora\Hornet\Tests\Fixtures\SentHeaders;

// The part of pollora/framework's doctor that the bridge implements: the package does not require the framework
require_once __DIR__.'/Stubs/doctor.php';
// Constants, a throwaway install folder, and the classes that the adapters name
require_once __DIR__.'/Stubs/wordpress.php';
// Loaded here so that the suite is the same whether one file runs or all of them
require_once __DIR__.'/Stubs/two-factor.php';
// Constants that several test files read
require_once __DIR__.'/Stubs/breached-password.php';

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

    // A function that one test stubbed stays declared for the tests that follow, and the translator asks whether these two are:
    // from then on they give the English back, unless a test says otherwise
    if (function_exists('translate')) {
        Functions\when('translate')->returnArg(1);
    }

    if (function_exists('translate_nooped_plural')) {
        Functions\when('translate_nooped_plural')->alias(static fn (array $nooped, int $count): string => $count === 1 ? $nooped['singular'] : $nooped['plural']);
    }
})->afterEach(function (): void {
    // Brain Monkey's expectations are checked when Mockery closes: count them as assertions, so a test made only of them is not "risky"
    $expectations = Mockery::getContainer()?->mockery_getExpectationCount() ?? 0;

    Monkey\tearDown();
    // What a service provider said about the host does not carry over to the next test
    HostApplication::set(null);
    SentHeaders::reset();
    FakeSessionTokens::$sessions = [];
    FakeApplicationPasswords::$passwords = [];
    FakeTheme::$coreDefault = null;
    Two_Factor_Core::$enabled = [];
    unset($GLOBALS['wpdb']);

    expect($expectations)->toBeGreaterThanOrEqual(0);
})->in('Unit');
