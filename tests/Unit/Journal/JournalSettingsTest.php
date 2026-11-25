<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;

it('is off until the owner turns it on, and keeps ninety days', function (): void {
    $journal = Settings::fromArray([], new ArraySecrets)->journal;

    expect($journal->enabled)->toBeFalse()->and($journal->retentionDays)->toBe(90);
});

it('reads what is set', function (): void {
    $journal = Settings::fromArray(['journal' => ['enabled' => true, 'retention_days' => 30]], new ArraySecrets)->journal;

    expect($journal->enabled)->toBeTrue()->and($journal->retentionDays)->toBe(30)
        ->and(Settings::fromArray(['journal' => []], new ArraySecrets)->journal->enabled)->toBeFalse();
});

it('refuses what it does not understand', function (mixed $config, string $message): void {
    expect(fn () => Settings::fromArray(['journal' => $config], new ArraySecrets))->toThrow(InvalidSettingsException::class, $message);
})->with([
    'not an array' => ['yes', 'journal'],
    'a typo' => [['enable' => true], 'enable'],
    'not a bool' => [['enabled' => 'yes'], 'journal.enabled'],
    'zero days' => [['retention_days' => 0], 'journal.retention_days'],
    'ten years and a day' => [['retention_days' => 3651], 'journal.retention_days'],
    'days that are not a number' => [['retention_days' => '30'], 'journal.retention_days'],
]);
