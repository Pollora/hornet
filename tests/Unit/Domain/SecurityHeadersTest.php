<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\ContentSecurityPolicy;
use Pollora\Hornet\Domain\Http\Context;
use Pollora\Hornet\Domain\Http\Hsts;
use Pollora\Hornet\Domain\Http\SecurityHeaders;

it('sends nothing when nothing is asked', function (): void {
    $headers = new SecurityHeaders;

    expect($headers->isActive())->toBeFalse()->and($headers->for(Context::Front, true))->toBe([]);
});

it('sends the simple headers everywhere', function (Context $context): void {
    $headers = new SecurityHeaders(true, 'strict-origin-when-cross-origin', 'camera=(), microphone=()');

    expect($headers->isActive())->toBeTrue()->and($headers->for($context, false))->toBe([
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=()',
    ]);
})->with([Context::Front, Context::Admin, Context::Login]);

it('sends HSTS over https only, since browsers ignore it over http', function (): void {
    $headers = new SecurityHeaders(hsts: new Hsts(31536000, true));

    expect($headers->for(Context::Front, true))->toBe(['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'])
        ->and($headers->for(Context::Front, false))->toBe([]);
});

it('keeps the policy off the dashboard and the login screen unless asked', function (): void {
    $front = new SecurityHeaders(csp: new ContentSecurityPolicy(false, "default-src 'self'"));
    $all = new SecurityHeaders(csp: new ContentSecurityPolicy(false, "default-src 'self'", null, true));

    expect($front->for(Context::Front, true))->toBe(['Content-Security-Policy-Report-Only' => "default-src 'self'"])
        ->and($front->for(Context::Admin, true))->toBe([])
        ->and($front->for(Context::Login, true))->toBe([])
        ->and($all->for(Context::Admin, true))->toBe(['Content-Security-Policy-Report-Only' => "default-src 'self'"]);
});

it('names the header after the mode', function (): void {
    expect((new ContentSecurityPolicy(true, "default-src 'self'"))->header())->toBe('Content-Security-Policy')
        ->and((new ContentSecurityPolicy(false, "default-src 'self'"))->header())->toBe('Content-Security-Policy-Report-Only');
});

it('adds the report address to the policy once', function (): void {
    expect((new ContentSecurityPolicy(false, "default-src 'self';", 'https://r.example.com/csp'))->policy)->toBe("default-src 'self'; report-uri https://r.example.com/csp")
        ->and((new ContentSecurityPolicy(false, "default-src 'self'; report-uri https://mine.example.com/x", 'https://r.example.com/csp'))->policy)->toBe("default-src 'self'; report-uri https://mine.example.com/x");
});

it('refuses a value that could start a second header', function (string $value): void {
    expect(fn () => new SecurityHeaders(permissionsPolicy: $value))->toThrow(InvalidSettingsException::class)
        ->and(fn () => new ContentSecurityPolicy(false, $value))->toThrow(InvalidSettingsException::class);
})->with([
    'a line break' => ["camera=()\r\nSet-Cookie: x=1"],
    'a newline' => ["camera=()\nX-Evil: 1"],
    'a null byte' => ["camera=()\0"],
    'empty' => ['  '],
    'too long' => [str_repeat('a', 5000)],
]);

it('refuses a report address that is not https or that smuggles a directive', function (string $uri): void {
    expect(fn () => new ContentSecurityPolicy(false, "default-src 'self'", $uri))->toThrow(InvalidSettingsException::class);
})->with(['http://r.example.com/x', 'ftp://r.example.com/x', 'nonsense', 'https://r.example.com/x; script-src *', 'https://r.example.com/a b']);

it('refuses a referrer policy that leaks more than the browser default', function (string $policy): void {
    expect(fn () => new SecurityHeaders(referrerPolicy: $policy))->toThrow(InvalidSettingsException::class, 'referrer_policy');
})->with(['unsafe-url', 'no-referrer-when-downgrade', 'nonsense']);

it('guards the HSTS rules that are hard to take back', function (): void {
    expect((new Hsts(300))->value())->toBe('max-age=300')
        ->and((new Hsts(63072000, true, true))->value())->toBe('max-age=63072000; includeSubDomains; preload')
        ->and(fn () => new Hsts(60))->toThrow(InvalidSettingsException::class, 'max_age')
        ->and(fn () => new Hsts(99999999))->toThrow(InvalidSettingsException::class, 'max_age')
        ->and(fn () => new Hsts(31536000, false, true))->toThrow(InvalidSettingsException::class, 'preload')
        ->and(fn () => new Hsts(86400, true, true))->toThrow(InvalidSettingsException::class, 'preload');
});
