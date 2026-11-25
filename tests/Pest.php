<?php

declare(strict_types=1);

use Brain\Monkey;
use Pollora\Hornet\Tests\Fixtures\FakeWpError;

// The part of pollora/framework's doctor that the bridge implements: the package does not require the framework
require_once __DIR__.'/Stubs/doctor.php';

// The unit suite has no WordPress: WP_Error is a small stand-in
if (! class_exists('WP_Error', false)) {
    class_alias(FakeWpError::class, 'WP_Error');
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

    expect($expectations)->toBeGreaterThanOrEqual(0);
})->in('Unit');
