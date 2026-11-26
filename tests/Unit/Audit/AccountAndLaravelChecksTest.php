<?php

declare(strict_types=1);

use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Checks\ApplicationPasswordsCheck;
use Pollora\Hornet\Domain\Audit\Checks\DebugModeCheck;
use Pollora\Hornet\Domain\Audit\Checks\LaravelDebugbarCheck;
use Pollora\Hornet\Domain\Audit\Checks\LaravelKeyCheck;
use Pollora\Hornet\Domain\Audit\Checks\OpenRegistrationCheck;
use Pollora\Hornet\Domain\Audit\Checks\PhpErrorDisplayCheck;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\ExposureCatalog;
use Pollora\Hornet\Infrastructure\Pollora\ConfiguredApplication;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;

function laravelApp(array $config = []): ConfiguredApplication
{
    $config = ['app.debug' => false, 'app.key' => 'base64:not-a-real-key', ...$config];

    return new ConfiguredApplication(static fn (string $key): mixed => $config[$key] ?? null);
}

function idsOf(array $checks): array
{
    return array_map(static fn (Check $check): string => $check->id(), $checks);
}

it('has the Laravel checks only on a Laravel application, and the PHP display one only elsewhere', function (): void {
    $classic = idsOf(DefaultChecks::all());
    $pollora = idsOf(DefaultChecks::all(false, laravelApp()));

    expect($classic)->toContain('php-error-display', 'open-registration', 'application-passwords')
        ->not->toContain('laravel-app-key')->not->toContain('laravel-debugbar')
        ->and($pollora)->toContain('laravel-app-key', 'laravel-debugbar', 'open-registration', 'application-passwords')
        ->not->toContain('php-error-display');
});

it('asks for the Laravel log only on a Laravel application', function (): void {
    $paths = static fn (bool $laravel): array => array_column(ExposureCatalog::all($laravel), 'path');

    expect($paths(false))->not->toContain('storage/logs/laravel.log')->and($paths(true))->toContain('storage/logs/laravel.log');
});

it('judges an open registration by what the default role may do', function (?array $capabilities, Outcome $outcome, ?Severity $severity): void {
    $environment = new FakeEnvironment(registration: $capabilities === null ? null : ['role' => 'custom', 'capabilities' => $capabilities]);
    $finding = (new OpenRegistrationCheck)->run($environment);

    expect($finding->outcome)->toBe($outcome);

    if ($severity !== null) {
        expect($finding->severity)->toBe($severity)->and($finding->summary)->toContain('"custom"');
    }
})->with([
    'closed' => [null, Outcome::Pass, null],
    'a reader' => [['read'], Outcome::Pass, null],
    'a writer' => [['read', 'edit_posts'], Outcome::Fail, Severity::Medium],
    'an editor' => [['read', 'edit_posts', 'edit_others_posts'], Outcome::Fail, Severity::High],
    'any HTML' => [['read', 'unfiltered_html'], Outcome::Fail, Severity::High],
    'an administrator' => [['read', 'edit_others_posts', 'manage_options'], Outcome::Fail, Severity::Critical],
    'one who promotes' => [['read', 'promote_users'], Outcome::Fail, Severity::Critical],
]);

it('reports the application passwords that nobody used for 90 days, by account number', function (): void {
    $now = (new FakeEnvironment)->now()->getTimestamp();
    $day = 86400;
    $check = new ApplicationPasswordsCheck;
    $run = static fn (?array $passwords) => $check->run(new FakeEnvironment(applicationPasswords: $passwords));

    expect($run(null)->outcome)->toBe(Outcome::Pass)
        ->and($run([])->outcome)->toBe(Outcome::Pass)
        // Used lately, or made lately and not used yet
        ->and($run([['user' => 3, 'created' => $now - 400 * $day, 'last_used' => $now - $day], ['user' => 3, 'created' => $now - 10 * $day, 'last_used' => null]])->outcome)->toBe(Outcome::Pass);

    $finding = $run([
        ['user' => 3, 'created' => $now - 400 * $day, 'last_used' => $now - 100 * $day],
        ['user' => 3, 'created' => $now - 100 * $day, 'last_used' => null],
        ['user' => 7, 'created' => $now - 100 * $day, 'last_used' => null],
        ['user' => 9, 'created' => $now - 400 * $day, 'last_used' => $now],
    ]);

    expect($finding->outcome)->toBe(Outcome::Fail)
        ->and($finding->severity)->toBe(Severity::Medium)
        ->and($finding->summary)->toStartWith('3 application passwords')
        ->and($finding->details)->toBe(['user #3: 2', 'user #7: 1']);

    $many = $run(array_map(static fn (int $user): array => ['user' => $user, 'created' => 0, 'last_used' => null], range(1, 7)));

    expect($many->details)->toHaveCount(6)->and($many->details[5])->toBe('and 2 more');
});

it('judges the display of PHP errors only in production, with WP_DEBUG off, from what the web server showed', function (): void {
    $check = new PhpErrorDisplayCheck;

    expect($check->run(new FakeEnvironment(type: 'local', phpErrors: true))->outcome)->toBe(Outcome::Pass)
        ->and($check->run(new FakeEnvironment(constants: ['WP_DEBUG' => true], phpErrors: true))->outcome)->toBe(Outcome::Pass)
        ->and($check->run(new FakeEnvironment(phpErrors: null))->outcome)->toBe(Outcome::Unknown)
        ->and($check->run(new FakeEnvironment(phpErrors: false))->outcome)->toBe(Outcome::Pass)
        ->and($check->run(new FakeEnvironment(phpErrors: true))->outcome)->toBe(Outcome::Fail)
        ->and($check->run(new FakeEnvironment(phpErrors: true))->severity)->toBe(Severity::Medium);
});

it('names APP_DEBUG when that is what to change, and once', function (): void {
    $production = new FakeEnvironment(constants: ['WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => true]);
    $finding = (new DebugModeCheck(laravelApp(['app.debug' => true])))->run($production);

    expect($finding->severity)->toBe(Severity::High)->and($finding->summary)->toContain('APP_DEBUG')->and($finding->fix)->toContain('APP_DEBUG=false')
        // Even when the project forces WP_DEBUG off in its constants
        ->and((new DebugModeCheck(laravelApp(['app.debug' => 'true'])))->run(new FakeEnvironment)->outcome)->toBe(Outcome::Fail)
        ->and((new DebugModeCheck(laravelApp()))->run(new FakeEnvironment)->outcome)->toBe(Outcome::Pass)
        ->and((new DebugModeCheck(laravelApp(['app.debug' => true])))->run(new FakeEnvironment(type: 'local'))->outcome)->toBe(Outcome::Pass);
});

it('reports a missing key and a forced Debugbar, and never the key itself', function (): void {
    expect((new LaravelKeyCheck(laravelApp(['app.key' => ''])))->run(new FakeEnvironment)->severity)->toBe(Severity::High)
        ->and((new LaravelKeyCheck(laravelApp(['app.key' => null])))->run(new FakeEnvironment)->outcome)->toBe(Outcome::Fail)
        ->and(json_encode((new LaravelKeyCheck(laravelApp()))->run(new FakeEnvironment)->toStoredArray()))->not->toContain('not-a-real-key');

    $debugbar = static fn (array $config, string $type = 'production') => (new LaravelDebugbarCheck(laravelApp($config)))->run(new FakeEnvironment(type: $type));
    // Debugbar 4: its own setting alone does nothing in production
    $four = static fn (mixed $enabled, mixed $force, array $more = []): array => ['debugbar.enabled' => $enabled, 'debugbar.force_allow_enable' => $force, ...$more];

    expect($debugbar($four(true, false))->outcome)->toBe(Outcome::Pass)
        ->and($debugbar($four(true, true))->severity)->toBe(Severity::High)
        ->and($debugbar($four('true', 'true'))->outcome)->toBe(Outcome::Fail)
        ->and($debugbar($four(false, true))->outcome)->toBe(Outcome::Pass)
        // Forced and following app.debug
        ->and($debugbar($four(null, true))->outcome)->toBe(Outcome::Pass)
        ->and($debugbar($four(null, true, ['app.debug' => true]))->outcome)->toBe(Outcome::Fail)
        // pollora/debugbar shows it to the allowed accounts only
        ->and($debugbar($four(true, true, ['debugbar-pollora.capability' => 'manage_options']))->severity)->toBe(Severity::Medium)
        // Debugbar 3 has no such key; not installed has neither
        ->and($debugbar(['debugbar.enabled' => true])->severity)->toBe(Severity::High)
        ->and($debugbar([])->outcome)->toBe(Outcome::Pass)
        ->and($debugbar($four(true, true), 'local')->outcome)->toBe(Outcome::Pass);
});
