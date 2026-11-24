<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;

it('starts with everything on and nothing set aside', function (): void {
    $settings = Settings::fromArray([]);

    expect($settings->enabled)->toBeTrue()
        ->and($settings->siteHealth)->toBeTrue()
        ->and($settings->disabledChecks)->toBe([])
        ->and($settings->ignored)->toBe([]);
});

it('reads the settings it knows', function (): void {
    $settings = Settings::fromArray([
        'enabled' => false,
        'site_health' => false,
        'disabled_checks' => ['xml-rpc'],
        'ignore' => [['check' => 'file-mods', 'reason' => '  Updates come from the dashboard.  ']],
    ]);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->siteHealth)->toBeFalse()
        ->and($settings->disabledChecks)->toBe(['xml-rpc'])
        ->and($settings->ignored)->toBe(['file-mods' => 'Updates come from the dashboard.']);
});

it('refuses a key it does not know, so that a typo cannot leave a protection off', function (): void {
    expect(fn (): Settings => Settings::fromArray(['enable' => false]))->toThrow(InvalidSettingsException::class, 'enable');
});

it('refuses a value of the wrong kind', function (mixed $config): void {
    expect(fn (): Settings => Settings::fromArray($config))->toThrow(InvalidSettingsException::class);
})->with([
    'enabled as a string' => [['enabled' => 'no']],
    'site_health as an int' => [['site_health' => 0]],
    'disabled_checks as a string' => [['disabled_checks' => 'xml-rpc']],
    'disabled_checks with an empty id' => [['disabled_checks' => ['']]],
    'disabled_checks keyed' => [['disabled_checks' => ['a' => 'xml-rpc']]],
    'ignore as a map' => [['ignore' => ['xml-rpc' => 'why']]],
    'ignore without a reason' => [['ignore' => [['check' => 'xml-rpc']]]],
    'ignore with a blank reason' => [['ignore' => [['check' => 'xml-rpc', 'reason' => '   ']]]],
    'ignore with an empty check' => [['ignore' => [['check' => '', 'reason' => 'why']]]],
]);

it('lets the settings of one site replace the shared ones', function (): void {
    $config = ['site_health' => true, 'sites' => [2 => ['site_health' => false]]];

    expect(SettingsLoader::merge($config, 1))->toBe(['site_health' => true])
        ->and(SettingsLoader::merge($config, 2))->toBe(['site_health' => false]);
});
