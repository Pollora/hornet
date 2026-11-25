<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\ExposedFilesTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Scan\ExposureCatalog;
use Pollora\Hornet\Domain\Scan\ProbeFiles;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\RecordingProbeFiles;
use Pollora\Hornet\Tests\Fixtures\RoutedHttpClient;

const SITE = 'https://example.com';
const PROBE_ID = '0123456789abcdef';

function exposedTask(RoutedHttpClient $http, ?ProbeFiles $files = null, ?int $budget = null, ?FakeClock $clock = null): ExposedFilesTask
{
    return new ExposedFilesTask($http, SITE, SITE.'/wp-content', SITE.'/wp-content/uploads', $files, $clock ?? new FakeClock, $budget, static fn (): string => PROBE_ID);
}

it('asks every address of the catalog, once, at the site and at its content folder', function (): void {
    $http = new RoutedHttpClient;

    $result = exposedTask($http)->run(null, false);

    expect($http->asked)->toHaveCount(count(ExposureCatalog::all()))
        ->and($http->asked)->toContain('https://example.com/.env')->toContain('https://example.com/wp-content/debug.log')->toContain('https://example.com/wp-content/uploads/')
        ->and($result->finding->outcome)->toBe(Outcome::Pass)
        ->and($result->finding->summary)->toBe(sprintf('None of the %d addresses asked gives away a file it should not.', count(ExposureCatalog::all())));
});

it('says an address that answers with a file is given away, and never repeats what the file holds', function (): void {
    $http = new RoutedHttpClient([
        SITE.'/.env' => new HttpResponse(200, null, "DB_PASSWORD=hunter2-very-secret\n"),
        SITE.'/wp-content/debug.log' => new HttpResponse(200, null, "[25-Nov-2026 10:00:00 UTC] PHP Warning:  x in /var/www/html/a.php\n"),
        SITE.'/wp-content/uploads/' => new HttpResponse(200, null, '<title>Index of /wp-content/uploads</title>'),
    ]);

    $finding = exposedTask($http)->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::High)
        ->and($finding->summary)->toBe('3 things are given away by the site itself.')
        ->and($finding->details[0])->toBe('[high] /.env answers, and it is environment variables (passwords, keys)')
        ->and($finding->details[1])->toStartWith('[medium] /wp-content/debug.log')
        ->and($finding->details[2])->toStartWith('[low] /wp-content/uploads/')
        ->and(json_encode($finding->toArray()))->not->toContain('hunter2')->not->toContain('/var/www/html')
        ->and($finding->fix)->toContain('assume that it was read');
});

it('is not fooled by a site that answers every address with its home page', function (): void {
    $http = new RoutedHttpClient(fallback: new HttpResponse(200, null, '<!doctype html><html><body><h1>Welcome</h1></body></html>'));

    expect(exposedTask($http)->run(null, false)->finding->outcome)->toBe(Outcome::Pass);
});

it('does not count a redirect, a refusal or an error as a file given away', function (): void {
    $http = new RoutedHttpClient([
        SITE.'/.env' => new HttpResponse(403, null, "DB_PASSWORD=x\n"),
        SITE.'/.git/HEAD' => new HttpResponse(301, null, "ref: refs/heads/main\n"),
        SITE.'/backup.sql' => new HttpResponse(500, null, 'CREATE TABLE x'),
    ]);

    expect(exposedTask($http)->run(null, false)->finding->outcome)->toBe(Outcome::Pass);
});

it('says it does not know when the site does not answer itself', function (): void {
    $result = exposedTask(new RoutedHttpClient(down: true))->run(null, false);

    expect($result->finding->outcome)->toBe(Outcome::Unknown)->and($result->finding->summary)->toContain('did not answer its own address');
});

it('says how many addresses did not answer, and goes on with the others', function (): void {
    $http = new RoutedHttpClient([SITE.'/.env' => new TransportException('timeout'), SITE.'/backup.sql' => new HttpResponse(200, null, "CREATE TABLE `a` (\n")]);

    $finding = exposedTask($http)->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->details)->toContain('1 of the '.count(ExposureCatalog::all()).' addresses did not answer.');
});

it('stops asking when the time is up and says how many it left', function (): void {
    $clock = new FakeClock;
    $http = new RoutedHttpClient(onAsk: static fn () => $clock->advance('PT10S'));

    $finding = exposedTask($http, budget: 25, clock: $clock)->run(null, false)->finding;

    expect($http->asked)->toHaveCount(3)
        ->and($finding->details)->toContain(sprintf('Not asked (out of time): %d addresses.', count(ExposureCatalog::all()) - 3));
});

describe('whether the server runs PHP in the uploads', function (): void {
    it('asks nothing of the disk unless it is given a place to write', function (): void {
        $http = new RoutedHttpClient;

        exposedTask($http)->run(null, false);

        expect(array_filter($http->asked, static fn (string $url): bool => str_contains($url, 'hornet-probe')))->toBe([]);
    });

    it('says high when the file it put there ran, and removes it', function (): void {
        $files = new RecordingProbeFiles;
        $name = 'hornet-probe-'.PROBE_ID.'.php';
        $http = new RoutedHttpClient([SITE.'/wp-content/uploads/'.$name => new HttpResponse(200, null, PROBE_ID."\n")]);

        $finding = exposedTask($http, $files)->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::High)
            ->and($finding->details[0])->toBe('[high] The server runs PHP that sits in the uploads folder: a file that is uploaded there is run')
            ->and($files->planted)->toBe([$name])->and($files->removed)->toBe([$name])->and($files->present)->toBe([]);
    });

    it('does not put the token in the file, so that a server that sends it as text is not taken for one that runs it', function (): void {
        $files = new RecordingProbeFiles;
        $name = 'hornet-probe-'.PROBE_ID.'.php';
        // What a server that does not run PHP would send: the file itself
        $http = new RoutedHttpClient(onAsk: static function (string $url): void {});
        $http->routes[SITE.'/wp-content/uploads/'.$name] = new HttpResponse(200, null, "<?php echo strrev('".strrev(PROBE_ID)."');\n");

        $finding = exposedTask($http, $files)->run(null, false)->finding;

        expect($files->contents[$name])->toBe("<?php echo strrev('".strrev(PROBE_ID)."');\n")
            ->and(str_contains($files->contents[$name], PROBE_ID))->toBeFalse()
            ->and($finding->outcome)->toBe(Outcome::Pass);
    });

    it('is fine when the server refuses or serves the file as it is, and removes it all the same', function (HttpResponse $answer): void {
        $files = new RecordingProbeFiles;
        $name = 'hornet-probe-'.PROBE_ID.'.php';

        $finding = exposedTask(new RoutedHttpClient([SITE.'/wp-content/uploads/'.$name => $answer]), $files)->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Pass)->and($files->removed)->toBe([$name]);
    })->with([
        'refused' => [new HttpResponse(403, null, 'Forbidden')],
        'not found' => [new HttpResponse(404, null, 'Not found')],
        'sent as text' => [new HttpResponse(200, null, "<?php echo strrev('fedcba9876543210');\n")],
        'something else' => [new HttpResponse(200, null, 'Hello')],
        'the token inside other text' => [new HttpResponse(200, null, 'xx'.PROBE_ID.'yy')],
    ]);

    it('says it could not ask when the uploads cannot be written to', function (): void {
        $finding = exposedTask(new RoutedHttpClient, new RecordingProbeFiles(writable: false))->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Pass)->and($finding->details)->toContain('Not asked: the uploads folder cannot be written to, so whether it runs PHP could not be tried.');
    });

    it('removes the file when the server does not answer for it', function (): void {
        $files = new RecordingProbeFiles;
        $name = 'hornet-probe-'.PROBE_ID.'.php';

        $finding = exposedTask(new RoutedHttpClient([SITE.'/wp-content/uploads/'.$name => new TransportException('timeout')]), $files)->run(null, false)->finding;

        expect($files->present)->toBe([])->and($files->removed)->toBe([$name])
            ->and($finding->details)->toContain('Not asked: the server did not answer for the file put in the uploads.');
    });
});
