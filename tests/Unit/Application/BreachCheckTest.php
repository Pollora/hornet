<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Password\BreachCheck;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;

// The hash of the word "password", worked out here and not written down: a secrets scanner takes a hash for a key
define('PASSWORD_PREFIX', substr(strtoupper(sha1('password')), 0, 5));
define('PASSWORD_SUFFIX', substr(strtoupper(sha1('password')), 5));

function breachCheck(string|TransportException $answer, int $status = 200): array
{
    $http = new FakeHttpClient;
    $http->answers = [$answer instanceof TransportException ? $answer : new HttpResponse($status, null, $answer)];

    return [new BreachCheck($http), $http];
}

it('finds a password in a breach, and how many times', function (): void {
    [$check] = breachCheck("0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n".PASSWORD_SUFFIX.":10434004\r\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:2");

    expect($check->occurrences('password'))->toBe(10434004);
});

it('says 0 when the password is not in the list', function (): void {
    [$check] = breachCheck("0018A45C4D1DEF81644B54AB7F969B88D65:1\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:2");

    expect($check->occurrences('password'))->toBe(0);
});

it('does not count the padding entries, which come back with a count of 0', function (): void {
    [$check] = breachCheck("0018A45C4D1DEF81644B54AB7F969B88D65:0\n".PASSWORD_SUFFIX.':0');

    expect($check->occurrences('password'))->toBe(0);
});

it('sends five characters of the hash and nothing else, asking for padding', function (): void {
    [$check, $http] = breachCheck('');
    $check->occurrences('password');
    $request = $http->gets[0];

    expect($request['url'])->toBe('https://api.pwnedpasswords.com/range/'.PASSWORD_PREFIX)
        ->and($request['headers'])->toBe(['Add-Padding' => 'true'])
        ->and(json_encode($http->gets))->not->toContain('"password"')->not->toContain(PASSWORD_SUFFIX)->not->toContain(strtolower(PASSWORD_SUFFIX))->not->toContain(strtoupper(sha1('password')))
        ->and($request['timeout'])->toBeLessThanOrEqual(5)
        // The answer is about 30 KB: a limit that holds it, and still bounds what is read
        ->and($request['maxBytes'])->toBeGreaterThan(50000)->toBeLessThanOrEqual(1048576)
        ->and($http->posts)->toBe([]);
});

it('compares the suffix without caring for case, and ignores lines it cannot read', function (): void {
    [$check] = breachCheck("garbage\n\n:::\n".strtolower(PASSWORD_SUFFIX).":7\nXYZ:abc");

    expect($check->occurrences('password'))->toBe(7);
});

it('fails open: a service that is down, slow or odd means "could not find out", never "breached"', function (): void {
    expect(breachCheck(new TransportException('The request failed (http_request_failed).'))[0]->occurrences('password'))->toBeNull()
        ->and(breachCheck('', 500)[0]->occurrences('password'))->toBeNull()
        ->and(breachCheck('', 429)[0]->occurrences('password'))->toBeNull()
        ->and(breachCheck('', 404)[0]->occurrences('password'))->toBeNull();
});

it('does not ask about an empty password', function (): void {
    [$check, $http] = breachCheck('');

    expect($check->occurrences(''))->toBeNull()->and($http->gets)->toBe([]);
});
