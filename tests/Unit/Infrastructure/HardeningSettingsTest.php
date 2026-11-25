<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\Context;
use Pollora\Hornet\Infrastructure\Config\HardeningSettings;
use Pollora\Hornet\Infrastructure\Config\HeaderSettings;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;

it('changes nothing until it is asked to', function (): void {
    $settings = Settings::fromArray([], new ArraySecrets);

    expect($settings->hardening->isActive())->toBeFalse()->and($settings->headers->isActive())->toBeFalse();
});

it('reads what to close', function (): void {
    $hardening = HardeningSettings::fromArray(['xmlrpc' => true, 'file_editor' => true]);

    expect($hardening->xmlRpc)->toBeTrue()->and($hardening->fileEditor)->toBeTrue()->and($hardening->userEnumeration)->toBeFalse()->and($hardening->hideVersion)->toBeFalse()->and($hardening->isActive())->toBeTrue();
});

it('refuses a hardening setting it does not know or that is not a flag', function (): void {
    expect(fn () => HardeningSettings::fromArray(['xml_rpc' => true]))->toThrow(InvalidSettingsException::class, 'xml_rpc')
        ->and(fn () => HardeningSettings::fromArray(['xmlrpc' => 'yes']))->toThrow(InvalidSettingsException::class, 'hardening.xmlrpc')
        ->and(fn () => HardeningSettings::fromArray('on'))->toThrow(InvalidSettingsException::class);
});

it('reads the headers', function (): void {
    $headers = HeaderSettings::fromArray([
        'content_type_options' => true,
        'referrer_policy' => 'same-origin',
        'permissions_policy' => 'camera=()',
        'hsts' => ['max_age' => 31536000, 'include_subdomains' => true],
        'csp' => ['mode' => 'enforce', 'policy' => "default-src 'self'", 'report_uri' => 'https://r.example.com/x', 'admin' => true],
    ]);

    expect($headers->for(Context::Admin, true))->toBe([
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=()',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'self'; report-uri https://r.example.com/x",
    ]);
});

it('starts a policy in report-only mode', function (): void {
    expect(array_keys(HeaderSettings::fromArray(['csp' => ['policy' => "default-src 'self'"]])->for(Context::Front, true)))->toBe(['Content-Security-Policy-Report-Only']);
});

it('refuses a header setting it does not understand', function (mixed $config, string $message): void {
    expect(fn () => HeaderSettings::fromArray($config))->toThrow(InvalidSettingsException::class, $message);
})->with([
    'not an array' => ['nosniff', 'headers'],
    'unknown key' => [['nosniff' => true], 'nosniff'],
    'flag that is not a bool' => [['content_type_options' => 'yes'], 'content_type_options'],
    'referrer that is not a string' => [['referrer_policy' => 5], 'referrer_policy'],
    'unknown referrer policy' => [['referrer_policy' => 'unsafe-url'], 'referrer_policy'],
    'hsts without an age' => [['hsts' => ['include_subdomains' => true]], 'max_age'],
    'hsts with an unknown key' => [['hsts' => ['max_age' => 31536000, 'always' => true]], 'always'],
    'hsts flags that are not bools' => [['hsts' => ['max_age' => 31536000, 'preload' => 'yes']], 'true or false'],
    'csp without a policy' => [['csp' => ['mode' => 'enforce']], 'policy'],
    'csp with an unknown key' => [['csp' => ['policy' => 'x', 'report' => 'y']], 'report'],
    'csp with a wrong mode' => [['csp' => ['policy' => "default-src 'self'", 'mode' => 'log']], 'report-only or enforce'],
    'csp with a header injection' => [['csp' => ['policy' => "default-src 'self'\r\nSet-Cookie: x=1"]], 'single line'],
]);
