<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Http\ContentSecurityPolicy;
use Pollora\Hornet\Domain\Http\Hsts;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Infrastructure\Pollora\SecurityHeadersMiddleware;
use Pollora\Hornet\Infrastructure\WordPress\HeadersModule;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

it('attaches the three places WordPress has: the public site, the dashboard, the login screen', function (): void {
    $hooks = new RecordingHooks;
    (new HeadersModule(new SecurityHeaders(true)))->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->toBe(['wp_headers', 'admin_init', 'login_init']);
});

it('sends in the dashboard and on the login screen after WordPress has sent its own, and sets the admin referrer policy', function (): void {
    $hooks = new RecordingHooks;
    (new HeadersModule(new SecurityHeaders(referrerPolicy: 'same-origin')))->register($hooks);
    $priorities = array_column($hooks->registered, 'priority', 'hook');

    expect($priorities['admin_init'])->toBeGreaterThan(10)->and($priorities['login_init'])->toBeGreaterThan(10)
        ->and($hooks->callback('admin_referrer_policy')())->toBe('same-origin');
});

it('leaves the admin referrer policy alone when none is asked', function (): void {
    $hooks = new RecordingHooks;
    (new HeadersModule(new SecurityHeaders(true)))->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->not->toContain('admin_referrer_policy');
});

it('adds the headers to the ones WordPress sends for the public site', function (): void {
    Functions\when('is_ssl')->justReturn(true);
    $module = new HeadersModule(new SecurityHeaders(true, null, null, new Hsts(31536000), new ContentSecurityPolicy(false, "default-src 'self'")));

    expect($module->front(['Content-Type' => 'text/html']))->toBe([
        'Content-Type' => 'text/html',
        'X-Content-Type-Options' => 'nosniff',
        'Strict-Transport-Security' => 'max-age=31536000',
        'Content-Security-Policy-Report-Only' => "default-src 'self'",
    ]);
});

it('does not send HSTS over plain http', function (): void {
    Functions\when('is_ssl')->justReturn(false);

    expect((new HeadersModule(new SecurityHeaders(hsts: new Hsts(31536000))))->front([]))->toBe([]);
});

describe('laravel middleware', function (): void {
    function laravelHeaders(?Closure $sent = null, ?SecurityHeaders $headers = null): SecurityHeadersMiddleware
    {
        return new SecurityHeadersMiddleware($headers ?? new SecurityHeaders(true, 'same-origin', null, new Hsts(31536000)), $sent ?? static fn (): array => []);
    }

    it('adds the headers to a response', function (): void {
        $response = laravelHeaders()->handle(Request::create('https://example.com/'), static fn (): Response => new Response('ok'));

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin')
            ->and($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
    });

    it('does not send HSTS over plain http', function (): void {
        $response = laravelHeaders()->handle(Request::create('http://example.com/'), static fn (): Response => new Response('ok'));

        expect($response->headers->has('Strict-Transport-Security'))->toBeFalse()->and($response->headers->has('X-Content-Type-Options'))->toBeTrue();
    });

    it('leaves a header the application set', function (): void {
        $response = laravelHeaders()->handle(Request::create('https://example.com/'), static function (): Response {
            $response = new Response('ok');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        });

        expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    });

    it('does not repeat a header PHP has already sent, whatever the case of its name', function (): void {
        $response = laravelHeaders(static fn (): array => ['x-content-type-options', 'Referrer-Policy'])->handle(Request::create('https://example.com/'), static fn (): Response => new Response('ok'));

        expect($response->headers->has('X-Content-Type-Options'))->toBeFalse()->and($response->headers->has('Referrer-Policy'))->toBeFalse()
            ->and($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
    });

    it('passes on what is not a response', function (): void {
        expect(laravelHeaders()->handle(Request::create('https://example.com/'), static fn (): string => 'not a response'))->toBe('not a response');
    });

    it('reads the headers PHP has sent when it is not told', function (): void {
        $response = (new SecurityHeadersMiddleware(new SecurityHeaders(true)))->handle(Request::create('https://example.com/'), static fn (): Response => new Response('ok'));

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });
});
