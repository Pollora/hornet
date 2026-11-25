<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;

it('gives an agent nothing until the owner says so, and reading is not writing', function (): void {
    $none = Settings::fromArray([], new ArraySecrets)->abilities;
    $read = Settings::fromArray(['abilities' => ['enabled' => true]], new ArraySecrets)->abilities;
    $write = Settings::fromArray(['abilities' => ['enabled' => true, 'writes' => true]], new ArraySecrets)->abilities;

    expect([$none->enabled, $none->writes])->toBe([false, false])
        ->and([$read->enabled, $read->writes])->toBe([true, false])
        ->and([$write->enabled, $write->writes])->toBe([true, true])
        ->and(Settings::fromArray(['abilities' => []], new ArraySecrets)->abilities->enabled)->toBeFalse();
});

it('refuses what it does not understand, and a write door that has no read door', function (mixed $config, string $message): void {
    expect(fn () => Settings::fromArray(['abilities' => $config], new ArraySecrets))->toThrow(InvalidSettingsException::class, $message);
})->with([
    'not an array' => ['yes', 'abilities'],
    'a typo' => [['enable' => true], 'enable'],
    'enabled not a bool' => [['enabled' => 'yes'], 'abilities.enabled'],
    'writes not a bool' => [['enabled' => true, 'writes' => 1], 'abilities.writes'],
    'writes without enabled' => [['writes' => true], 'abilities.writes'],
    'writes with enabled false' => [['enabled' => false, 'writes' => true], 'abilities.writes'],
]);
