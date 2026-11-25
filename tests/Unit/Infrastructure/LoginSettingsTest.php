<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Infrastructure\Config\LoginSettings;
use Pollora\Hornet\Infrastructure\Config\NetworkSettings;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;

it('is off until it is asked for', function (): void {
    $settings = Settings::fromArray([], new ArraySecrets);

    expect($settings->login->throttle)->toBeFalse()->and($settings->network->isTrusted('10.0.0.5'))->toBeFalse();
});

it('reads the throttle', function (): void {
    $login = LoginSettings::fromArray(['throttle' => true, 'max_attempts' => 3, 'window' => 600, 'lockout' => 1200, 'allow_ips' => ['203.0.113.0/24', '2001:db8::1']]);

    expect($login->throttle)->toBeTrue()->and($login->policy->maxAttempts)->toBe(3)->and($login->policy->window)->toBe(600)->and($login->policy->lockout)->toBe(1200)
        ->and($login->isAllowed('203.0.113.99'))->toBeTrue()->and($login->isAllowed('2001:db8::1'))->toBeTrue()->and($login->isAllowed('203.0.114.1'))->toBeFalse();
});

it('uses a fair default: 5 failures in 15 minutes, 15 minutes locked', function (): void {
    $policy = LoginSettings::fromArray(['throttle' => true])->policy;

    expect([$policy->maxAttempts, $policy->window, $policy->lockout])->toBe([5, 900, 900]);
});

it('refuses a login setting it does not understand', function (array $config, string $message): void {
    expect(fn () => LoginSettings::fromArray($config))->toThrow(InvalidSettingsException::class, $message);
})->with([
    'unknown key' => [['throttel' => true], 'throttel'],
    'throttle not a bool' => [['throttle' => 'yes'], 'login.throttle'],
    'zero attempts' => [['max_attempts' => 0], 'login.max_attempts'],
    'too many attempts' => [['max_attempts' => 1000], 'login.max_attempts'],
    'a window that is not a number' => [['window' => '15m'], 'login.window'],
    'a window of one second' => [['window' => 1], 'login.window'],
    'a lockout of more than a day' => [['lockout' => 999999], 'login.lockout'],
    'allow_ips that is a string' => [['allow_ips' => '203.0.113.7'], 'login.allow_ips'],
    'allow_ips with a bad address' => [['allow_ips' => ['203.0.113.7', 'nope']], 'login.allow_ips'],
    'allow_ips that is not a list' => [['allow_ips' => ['a' => '203.0.113.7']], 'login.allow_ips'],
]);

it('reads the proxies and the header', function (): void {
    $proxies = NetworkSettings::fromArray(['trusted_proxies' => ['10.0.0.0/8', '2001:db8::/32'], 'forwarded_header' => 'CF-Connecting-IP']);

    expect($proxies->isTrusted('10.2.3.4'))->toBeTrue()->and($proxies->isTrusted('2001:db8::7'))->toBeTrue()->and($proxies->isTrusted('8.8.8.8'))->toBeFalse()->and($proxies->header())->toBe('CF-Connecting-IP');
});

it('refuses a network setting it does not understand', function (array $config, string $message): void {
    expect(fn () => NetworkSettings::fromArray($config))->toThrow(InvalidSettingsException::class, $message);
})->with([
    'unknown key' => [['proxies' => []], 'proxies'],
    'a header that may not be read' => [['forwarded_header' => 'X-Evil'], 'forwarded_header'],
    'a proxy that is not an address' => [['trusted_proxies' => ['cloudflare']], 'trusted_proxies'],
    'a proxy range that is too wide for its family' => [['trusted_proxies' => ['10.0.0.0/40']], 'trusted_proxies'],
    'proxies that are a string' => [['trusted_proxies' => '10.0.0.0/8'], 'trusted_proxies'],
]);

it('refuses a network section that is not an array', function (): void {
    expect(fn () => NetworkSettings::fromArray('10.0.0.0/8'))->toThrow(InvalidSettingsException::class, 'network');
});
