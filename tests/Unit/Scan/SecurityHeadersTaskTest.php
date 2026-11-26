<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\SecurityHeadersTask;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;

function page(array $headers = [], int $status = 200): HttpResponse
{
    return new HttpResponse($status, null, '<html></html>', $headers);
}

function headersFinding(array $answers, string $home = 'https://example.com/', bool $production = true, ?SecurityHeaders $configured = null, ?FakeHttpClient &$http = null): Finding
{
    $http = new FakeHttpClient;
    $http->answers = $answers;

    return (new SecurityHeadersTask($http, $home, $production, $configured ?? new SecurityHeaders))->run(null, false)->finding;
}

const ALL_THREE = ['x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN', 'strict-transport-security' => 'max-age=31536000'];

it('names what the home page does not send, in one finding', function (): void {
    $finding = headersFinding([page()]);

    expect($finding->outcome)->toBe(Outcome::Fail)
        ->and($finding->severity)->toBe(Severity::Low)
        ->and($finding->summary)->toStartWith('3 security header problems')
        ->and($finding->details)->toHaveCount(3)
        ->and(headersFinding([page(ALL_THREE)])->outcome)->toBe(Outcome::Pass);
});

it('accepts frame-ancestors for the frame protection, and not any value of the headers', function (): void {
    $headers = ['x-content-type-options' => 'nosniff', 'strict-transport-security' => 'max-age=300', 'content-security-policy' => "default-src 'self'; frame-ancestors 'none'"];

    expect(headersFinding([page($headers)])->outcome)->toBe(Outcome::Pass)
        ->and(headersFinding([page([...ALL_THREE, 'strict-transport-security' => 'max-age=0'])])->details[0])->toContain('Strict-Transport-Security')
        ->and(headersFinding([page([...ALL_THREE, 'x-frame-options' => 'ALLOWALL'])])->details[0])->toContain('frame');
});

it('does not ask an http address for HSTS', function (): void {
    $headers = ['x-content-type-options' => 'nosniff', 'x-frame-options' => 'DENY'];

    expect(headersFinding([page($headers)], 'http://example.com/')->outcome)->toBe(Outcome::Pass);
});

it('says when a header Hornet is set to send does not arrive', function (): void {
    $configured = new SecurityHeaders(true, 'same-origin');
    $finding = headersFinding([page(ALL_THREE)], configured: $configured);

    expect($finding->outcome)->toBe(Outcome::Fail)
        ->and($finding->details)->toHaveCount(1)
        ->and($finding->details[0])->toStartWith('Referrer-Policy is turned on')
        ->and(headersFinding([page([...ALL_THREE, 'referrer-policy' => 'same-origin'])], configured: $configured)->outcome)->toBe(Outcome::Pass);
});

it('follows one redirection to the same host, and nowhere else', function (string $location, string $asked): void {
    $finding = headersFinding([page(['location' => $location], 301), page(ALL_THREE)], http: $http);

    expect($finding->outcome)->toBe(Outcome::Pass)->and($http->gets[1]['url'])->toBe($asked);
})->with([
    'a path' => ['/fr/', 'https://example.com/fr/'],
    'an address' => ['https://example.com/en/', 'https://example.com/en/'],
]);

it('does not judge what is not the home page', function (HttpResponse|TransportException $answer): void {
    $finding = headersFinding([$answer], http: $http);

    expect($finding->outcome)->toBe(Outcome::Unknown)->and($http->gets)->toHaveCount(1);
})->with([
    'a password wall' => [page([], 401)],
    'an error' => [page([], 503)],
    'a redirection elsewhere' => [page(['location' => 'https://other.test/'], 302)],
    'a redirection to another host written without a scheme' => [page(['location' => '//other.test/'], 302)],
    'no answer' => [new TransportException('x')],
]);

it('asks nothing of a site that is not in production', function (): void {
    expect(headersFinding([], production: false, http: $http)->outcome)->toBe(Outcome::Pass)->and($http->gets)->toBe([]);
});
