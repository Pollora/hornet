<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\ExposedFilesTask;
use Pollora\Hornet\Application\Scan\SecurityHeadersTask;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Scan\ExposureCatalog;
use Pollora\Hornet\Domain\Scan\ProbeFiles;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\RecordingProbeFiles;
use Pollora\Hornet\Tests\Fixtures\RoutedHttpClient;

/**
 * The home page answers with these, in this order.
 *
 * @param  list<HttpResponse|TransportException>  $answers
 * @return array{Finding, FakeHttpClient}
 */
function mutAppHeaders(array $answers, string $home = 'https://example.com/', bool $production = true, ?SecurityHeaders $configured = null): array
{
    $http = new FakeHttpClient;
    $http->answers = $answers;

    return [(new SecurityHeadersTask($http, $home, $production, $configured ?? new SecurityHeaders))->run(null, false)->finding, $http];
}

/**
 * @param  array<string, string>  $headers
 */
function mutAppPage(array $headers = [], int $status = 200): HttpResponse
{
    return new HttpResponse($status, null, '', $headers);
}

function mutAppGoodHeaders(): array
{
    return ['x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN', 'strict-transport-security' => 'max-age=600'];
}

it('does not judge, nor ask, a site that is not in production', function (): void {
    [$finding, $http] = mutAppHeaders([], production: false);

    expect($finding->outcome)->toBe(Outcome::Pass)->and($http->gets)->toBe([]);
});

it('asks the home page once, reading little, waiting little, and allowed to reach its own private address', function (): void {
    [$finding, $http] = mutAppHeaders([mutAppPage(mutAppGoodHeaders())]);

    expect($finding->outcome)->toBe(Outcome::Pass)
        ->and($http->gets)->toBe([['url' => 'https://example.com/', 'headers' => [], 'maxBytes' => 1024, 'timeout' => 8, 'private' => true]]);
});

it('does not know when the home page does not answer, or answers something else than a page', function (HttpResponse|TransportException $answer): void {
    [$finding] = mutAppHeaders([$answer]);

    expect($finding->outcome)->toBe(Outcome::Unknown);
})->with(['down' => [new TransportException('down')], 'refused' => [new HttpResponse(403)], 'broken' => [new HttpResponse(500)], 'not found' => [new HttpResponse(404)], 'no content' => [new HttpResponse(204)]]);

it('follows one redirection to the same host', function (string $home, string $location, string $next): void {
    [$finding, $http] = mutAppHeaders([mutAppPage(['location' => $location], 301), mutAppPage(mutAppGoodHeaders())], $home);

    expect(array_column($http->gets, 'url'))->toBe([$home, $next])->and($finding->outcome)->toBe(Outcome::Pass);
})->with([
    'a path' => ['https://example.com/', '/fr/', 'https://example.com/fr/'],
    'a path on another port' => ['http://example.com:8080/', '/fr/', 'http://example.com:8080/fr/'],
    'a whole address' => ['http://example.com/', 'https://example.com/', 'https://example.com/'],
    'a scheme in capitals' => ['http://example.com/', 'HTTPS://example.com/en/', 'HTTPS://example.com/en/'],
]);

it('never follows a redirection to somewhere else', function (string $location): void {
    [$finding, $http] = mutAppHeaders([mutAppPage(['location' => $location], 302), mutAppPage(mutAppGoodHeaders())]);

    expect($http->gets)->toHaveCount(1)->and($finding->outcome)->toBe(Outcome::Unknown)->and($finding->summary)->toContain('302');
})->with([
    'another host' => 'https://evil.example.net/',
    'a scheme-relative address' => '//evil.example.net/x',
    'a relative path' => 'fr/',
    'another scheme' => 'ftp://example.com/',
    'an address inside a path' => 'x/https://example.com/',
    'nothing' => '',
]);

it('follows only a 3xx, and only once', function (): void {
    [$ok, $okHttp] = mutAppHeaders([mutAppPage(['location' => '/x', ...mutAppGoodHeaders()])]);
    [$late, $lateHttp] = mutAppHeaders([mutAppPage(['location' => '/x'], 400), mutAppPage(mutAppGoodHeaders())]);
    [$early, $earlyHttp] = mutAppHeaders([mutAppPage(['location' => '/x'], 299), mutAppPage(mutAppGoodHeaders())]);
    [$edge, $edgeHttp] = mutAppHeaders([mutAppPage(['location' => '/x'], 300), mutAppPage(mutAppGoodHeaders())]);
    [$twice, $twiceHttp] = mutAppHeaders([mutAppPage(['location' => '/x'], 399), mutAppPage(['location' => '/y'], 301), mutAppPage(mutAppGoodHeaders())]);

    expect($okHttp->gets)->toHaveCount(1)->and($ok->outcome)->toBe(Outcome::Pass)
        ->and($lateHttp->gets)->toHaveCount(1)->and($late->outcome)->toBe(Outcome::Unknown)
        ->and($earlyHttp->gets)->toHaveCount(1)->and($early->outcome)->toBe(Outcome::Unknown)
        ->and($edgeHttp->gets)->toHaveCount(2)->and($edge->outcome)->toBe(Outcome::Pass)
        ->and($twiceHttp->gets)->toHaveCount(2)->and($twice->outcome)->toBe(Outcome::Unknown);
});

it('judges each of the three headers on its own', function (array $headers, array $expected): void {
    [$finding] = mutAppHeaders([mutAppPage($headers)]);

    expect($finding->details)->toHaveCount(count($expected));

    foreach ($expected as $index => $start) {
        expect($finding->details[$index])->toStartWith($start);
    }
})->with([
    'nothing at all' => [[], ['X-Content-Type-Options', 'Nothing stops', 'Strict-Transport-Security']],
    'nosniff in any case' => [[...mutAppGoodHeaders(), 'x-content-type-options' => 'NoSniff'], []],
    'another value than nosniff' => [[...mutAppGoodHeaders(), 'x-content-type-options' => 'sniff'], ['X-Content-Type-Options']],
    'deny in capitals' => [[...mutAppGoodHeaders(), 'x-frame-options' => 'DENY'], []],
    'allow-from is not a protection' => [[...mutAppGoodHeaders(), 'x-frame-options' => 'ALLOW-FROM https://a.example'], ['Nothing stops']],
    'frame-ancestors in the policy' => [['x-content-type-options' => 'nosniff', 'content-security-policy' => "Frame-Ancestors 'self'", 'strict-transport-security' => 'max-age=1'], []],
    'a policy without it' => [['x-content-type-options' => 'nosniff', 'content-security-policy' => "default-src 'self'", 'strict-transport-security' => 'max-age=1'], ['Nothing stops']],
    'max-age of zero turns it off' => [[...mutAppGoodHeaders(), 'strict-transport-security' => 'max-age=0'], ['Strict-Transport-Security']],
    'a quoted max-age' => [[...mutAppGoodHeaders(), 'strict-transport-security' => 'Max-Age = "300"'], []],
]);

it('does not ask HSTS of a plain HTTP address, and fails low with a count', function (): void {
    [$plain] = mutAppHeaders([mutAppPage()], 'http://example.com/');
    [$one] = mutAppHeaders([mutAppPage([...mutAppGoodHeaders(), 'x-frame-options' => ''])]);

    expect($plain->details)->toHaveCount(2)
        ->and($plain->outcome)->toBe(Outcome::Fail)
        ->and($plain->severity)->toBe(Severity::Low)
        ->and($plain->summary)->toBe('2 security header problems on the home page.')
        ->and($one->summary)->toBe('1 security header problem on the home page.')
        ->and($one->fix)->toContain('server-config nginx --headers');
});

it('says which header Hornet is set to send does not arrive, whatever its case', function (): void {
    $configured = new SecurityHeaders(true, 'no-referrer');
    [$arrives] = mutAppHeaders([mutAppPage([...mutAppGoodHeaders(), 'referrer-policy' => 'no-referrer'])], configured: $configured);
    [$dropped] = mutAppHeaders([mutAppPage(mutAppGoodHeaders())], configured: $configured);

    expect($arrives->outcome)->toBe(Outcome::Pass)
        ->and($dropped->outcome)->toBe(Outcome::Fail)
        ->and($dropped->details)->toHaveCount(1)
        ->and($dropped->details[0])->toStartWith('Referrer-Policy is turned on');
});

it('lists the missing headers and then the dropped ones together', function (): void {
    [$finding] = mutAppHeaders([mutAppPage(['x-frame-options' => 'DENY', 'strict-transport-security' => 'max-age=1'])], configured: new SecurityHeaders(false, 'no-referrer'));

    expect($finding->details)->toHaveCount(2)->and($finding->summary)->toStartWith('2 ');
});

// Exposed files

function mutAppExposed(RoutedHttpClient|FakeHttpClient $http, ?ProbeFiles $files = null, ?int $budget = null, ?FakeClock $clock = null): ExposedFilesTask
{
    return new ExposedFilesTask($http, 'https://example.com', 'https://example.com/wp-content', 'https://example.com/wp-content/uploads', $files, $clock ?? new FakeClock, $budget, static fn (): string => 'abcdef0123456789');
}

it('reads the first 8 KiB only of each address, eight seconds at most, and may reach its own private address', function (): void {
    $http = new FakeHttpClient;

    mutAppExposed($http)->run(null, false);

    expect(array_unique(array_column($http->gets, 'maxBytes')))->toBe([8192])
        ->and(array_unique(array_column($http->gets, 'timeout')))->toBe([8])
        ->and(array_unique(array_column($http->gets, 'private')))->toBe([true])
        ->and(array_column($http->gets, 'headers'))->each->toBe([]);
});

it('asks no further address once the budget is spent, and says how many it left', function (): void {
    $clock = new FakeClock;
    $http = new RoutedHttpClient(onAsk: static fn () => $clock->advance('PT10S'));
    $total = count(ExposureCatalog::all());

    $finding = mutAppExposed($http, budget: 10, clock: $clock)->run(null, false)->finding;

    // The second question is asked at the very deadline, the third is not
    expect($http->asked)->toHaveCount(2)
        ->and($finding->details)->toBe([sprintf('Not asked (out of time): %d addresses.', $total - 2)])
        ->and($finding->summary)->toBe('None of the 2 addresses asked gives away a file it should not.');
});

it('has no time limit without a budget', function (): void {
    $clock = new FakeClock;
    $http = new RoutedHttpClient(onAsk: static fn () => $clock->advance('PT1H'));

    $finding = mutAppExposed($http, clock: $clock)->run(null, false)->finding;

    expect($http->asked)->toHaveCount(count(ExposureCatalog::all()))->and($finding->details)->toBe([]);
});

it('counts the addresses that did not answer apart from those that were asked', function (): void {
    $http = new RoutedHttpClient(['https://example.com/.env' => new TransportException('x'), 'https://example.com/.git/HEAD' => new TransportException('x')]);
    $total = count(ExposureCatalog::all());

    $finding = mutAppExposed($http)->run(null, false)->finding;

    expect($finding->details)->toBe([sprintf('2 of the %d addresses did not answer.', $total)])
        ->and($finding->summary)->toBe(sprintf('None of the %d addresses asked gives away a file it should not.', $total - 2));
});

it('puts the notes after what is given away, and says one thing in the singular', function (): void {
    $http = new RoutedHttpClient(['https://example.com/.env' => new HttpResponse(200, null, "APP_KEY=x\n"), 'https://example.com/backup.sql' => new TransportException('x')]);

    $finding = mutAppExposed($http)->run(null, false)->finding;

    expect($finding->summary)->toBe('1 thing is given away by the site itself.')
        ->and($finding->details)->toHaveCount(2)
        ->and($finding->details[0])->toStartWith('[high] /.env')
        ->and($finding->details[1])->toContain('did not answer');
});

it('plants a script that prints the token from its reverse, asks for it, and removes it', function (): void {
    $files = new RecordingProbeFiles;
    $http = new RoutedHttpClient(['https://example.com/wp-content/uploads/hornet-probe-abcdef0123456789.php' => new HttpResponse(200, null, " abcdef0123456789\n")]);

    $finding = mutAppExposed($http, $files)->run(null, false)->finding;

    expect($files->contents)->toBe(['hornet-probe-abcdef0123456789.php' => "<?php echo strrev('9876543210fedcba');\n"])
        ->and($files->removed)->toBe(['hornet-probe-abcdef0123456789.php'])
        ->and($finding->outcome)->toBe(Outcome::Fail)
        ->and($finding->severity)->toBe(Severity::High)
        ->and($finding->details[0])->toStartWith('[high] The server runs PHP');
});

it('reads at most 256 bytes of what the planted file answers', function (): void {
    $http = new FakeHttpClient;

    mutAppExposed($http, new RecordingProbeFiles)->run(null, false);
    $last = $http->gets[array_key_last($http->gets)];

    expect($last['maxBytes'])->toBe(256)->and($last['timeout'])->toBe(8)->and($last['private'])->toBeTrue();
});

it('does not say that PHP runs when the file is refused or sent as it is', function (HttpResponse $answer): void {
    $http = new RoutedHttpClient(['https://example.com/wp-content/uploads/hornet-probe-abcdef0123456789.php' => $answer]);

    expect(mutAppExposed($http, new RecordingProbeFiles)->run(null, false)->finding->outcome)->toBe(Outcome::Pass);
})->with([
    'the token with another status' => [new HttpResponse(403, null, 'abcdef0123456789')],
    'the source' => [new HttpResponse(200, null, "<?php echo strrev('9876543210fedcba');")],
    'the token among other things' => [new HttpResponse(200, null, '<p>abcdef0123456789</p>')],
]);

it('says that the question could not be asked, and removes the file when it did plant it', function (): void {
    $silent = new RecordingProbeFiles;
    $refused = new RecordingProbeFiles(writable: false);
    $down = new RoutedHttpClient(['https://example.com/wp-content/uploads/hornet-probe-abcdef0123456789.php' => new TransportException('x')]);

    $unanswered = mutAppExposed($down, $silent)->run(null, false)->finding;
    $unwritten = mutAppExposed(new RoutedHttpClient, $refused)->run(null, false)->finding;

    expect($unanswered->details)->toBe(['Not asked: the server did not answer for the file put in the uploads.'])
        ->and($silent->removed)->toHaveCount(1)
        ->and($unwritten->details)->toBe(['Not asked: the uploads folder cannot be written to, so whether it runs PHP could not be tried.']);
});

it('puts what the server runs above what it merely lists', function (): void {
    $http = new RoutedHttpClient([
        'https://example.com/wp-content/uploads/' => new HttpResponse(200, null, '<title>Index of /wp-content/uploads</title>'),
        'https://example.com/wp-content/uploads/hornet-probe-abcdef0123456789.php' => new HttpResponse(200, null, 'abcdef0123456789'),
    ]);

    $finding = mutAppExposed($http, new RecordingProbeFiles)->run(null, false)->finding;

    expect($finding->severity)->toBe(Severity::High)->and($finding->details[0])->toStartWith('[high] The server runs PHP')->and($finding->details[1])->toStartWith('[low]');
});
