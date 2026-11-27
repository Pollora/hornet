<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Password\BreachCheck;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;

/**
 * @return array{BreachCheck, FakeHttpClient, string, string} the check, what it sends, and the two parts of the hash of the password
 */
function mutAppBreach(string $password, Closure $body): array
{
    $hash = strtoupper(sha1($password));
    $suffix = substr($hash, 5);
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse(200, null, $body($suffix))];

    return [new BreachCheck($http), $http, substr($hash, 0, 5), $suffix];
}

it('bounds the answer it reads to 256 KiB, and waits four seconds at most', function (): void {
    [$check, $http] = mutAppBreach('correct horse', static fn (): string => '');

    $check->occurrences('correct horse');

    expect($http->gets[0]['maxBytes'])->toBe(262144)
        ->and($http->gets[0]['timeout'])->toBe(4)
        // A public service: the request must not be allowed to reach a private address
        ->and($http->gets[0]['private'])->toBeFalse();
});

it('reads the lines of an answer however they end', function (string $end): void {
    [$check] = mutAppBreach('correct horse', static fn (string $suffix): string => 'AAAA:1'.$end.$suffix.':12'.$end.'BBBB:3');

    expect($check->occurrences('correct horse'))->toBe(12);
})->with(['CRLF' => "\r\n", 'LF' => "\n", 'CR' => "\r"]);

it('does not mind spaces around a line, and keeps the count that follows the first colon only', function (): void {
    [$spaced] = mutAppBreach('correct horse', static fn (string $suffix): string => '  '.$suffix.':5  ');
    [$doubled] = mutAppBreach('correct horse', static fn (string $suffix): string => $suffix.':5:9');

    expect($spaced->occurrences('correct horse'))->toBe(5)
        // "5:9" is not a number: the line cannot be read, and says nothing
        ->and($doubled->occurrences('correct horse'))->toBe(0);
});

it('takes a suffix that comes without a count as not breached', function (): void {
    [$check] = mutAppBreach('correct horse', static fn (string $suffix): string => $suffix);

    expect($check->occurrences('correct horse'))->toBe(0);
});
