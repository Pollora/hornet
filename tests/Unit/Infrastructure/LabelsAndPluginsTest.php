<?php

declare(strict_types=1);

use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\LaravelApplication;

it('names every check for a person, each one differently', function (): void {
    $laravel = Mockery::mock(LaravelApplication::class);
    $checks = DefaultChecks::all(true, $laravel, true);
    $labels = array_map(static fn ($check): string => $check->label(), $checks);

    expect($labels)->toHaveCount(count(array_unique($labels)));

    foreach ($checks as $check) {
        expect($check->label())->not->toBe('')->not->toBe($check->id());
    }
});

/*
 * The unit suite declares a stand-in for the Two-Factor plugin for its whole life: a site without it is another process.
 */
it('counts nobody as enrolled on a site without the Two-Factor plugin', function (): void {
    $script = <<<'PHP'
    require getcwd().'/vendor/autoload.php';
    $enrollment = new Pollora\Hornet\Infrastructure\WordPress\TwoFactorEnrollment;
    echo var_export($enrollment->available(), true), ' ', var_export($enrollment->enrolled(1), true);
    PHP;

    exec(sprintf('cd %s && %s -r %s 2>&1', escapeshellarg(dirname(__DIR__, 3)), escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $code);

    expect($code)->toBe(0)->and(implode("\n", $output))->toBe('false false');
});
