<?php

declare(strict_types=1);

use Brain\Monkey;

// The part of pollora/framework's doctor that the bridge implements: the package does not require the framework
require_once __DIR__.'/Stubs/doctor.php';

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
