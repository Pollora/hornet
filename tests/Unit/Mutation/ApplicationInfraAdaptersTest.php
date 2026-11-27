<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Network\Cidr;
use Pollora\Hornet\Domain\Network\TrustedProxies;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Scan\ComposerPackage;
use Pollora\Hornet\Infrastructure\Login\ClientAddress;
use Pollora\Hornet\Infrastructure\Scan\InstalledComposerPackages;
use Pollora\Hornet\Infrastructure\Scan\NativeFileSystem;
use Pollora\Hornet\Infrastructure\Scan\OsvAdvisoryDatabase;
use Pollora\Hornet\Infrastructure\Scan\UploadsProbeFiles;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;

/**
 * An empty folder of its own.
 */
function mutAppFolder(): string
{
    $folder = sys_get_temp_dir().'/hornet-mut-'.bin2hex(random_bytes(6));
    mkdir($folder, 0777, true);

    return $folder;
}

function mutAppWrite(string $path, string $contents): void
{
    is_dir(dirname($path)) || mkdir(dirname($path), 0777, true);
    file_put_contents($path, $contents);
}

// The disk

it('reads files under its root only, whatever the path says', function (): void {
    $base = mutAppFolder();
    mutAppWrite($base.'/secret.txt', 'secret');
    mutAppWrite($base.'/root/a.txt', 'abcdef');
    mutAppWrite($base.'/root/sub/b.txt', 'b');
    $files = NativeFileSystem::at($base.'/root/');

    foreach (['../secret.txt', 'sub/../../secret.txt', $base.'/secret.txt'] as $outside) {
        expect($files->exists($outside))->toBeFalse()
            ->and($files->read($outside, 100))->toBeNull()
            ->and($files->md5($outside))->toBeNull()
            ->and($files->sha256($outside))->toBeNull()
            ->and($files->size($outside))->toBeNull();
    }

    expect($files->files('..', true))->toBeNull()
        ->and($files->files('', true))->toBe(['a.txt', 'sub/b.txt'])
        ->and($files->files('', false))->toBe(['a.txt'])
        ->and($files->files('sub', true))->toBe(['sub/b.txt'])
        ->and($files->files('a.txt', true))->toBeNull()
        ->and($files->files('nope', true))->toBeNull()
        ->and($files->exists('sub'))->toBeTrue()
        ->and($files->read('a.txt', 3))->toBe('abc')
        ->and($files->read('a.txt', 0))->toBe('a')
        ->and($files->md5('a.txt'))->toBe(md5('abcdef'))
        ->and($files->sha256('a.txt'))->toBe(hash('sha256', 'abcdef'))
        ->and($files->size('a.txt'))->toBe(6)
        // A folder is not a file
        ->and($files->read('sub', 5))->toBeNull()
        ->and($files->md5('sub'))->toBeNull()
        ->and($files->sha256('sub'))->toBeNull()
        ->and($files->size('sub'))->toBeNull();
});

it('lists hidden files, sorted by bytes', function (): void {
    $base = mutAppFolder();

    foreach (['b.php', 'B.php', '.hidden.php', 'a10', 'a9'] as $name) {
        mutAppWrite($base.'/'.$name, 'x');
    }

    expect(NativeFileSystem::at($base)->files('', true))->toBe(['.hidden.php', 'B.php', 'a10', 'a9', 'b.php'])
        ->and(NativeFileSystem::at($base)->files('', false))->toBe(['.hidden.php', 'B.php', 'a10', 'a9', 'b.php']);
});

it('has no file system for what is not a folder', function (): void {
    $base = mutAppFolder();
    mutAppWrite($base.'/file', 'x');

    expect(NativeFileSystem::at($base.'/nope'))->toBeNull()->and(NativeFileSystem::at($base.'/file'))->toBeNull();
});

// The probe file

it('writes and removes only a file with its own name', function (string $name): void {
    $folder = mutAppFolder();
    mutAppWrite($folder.'/'.basename($name), 'keep me');
    $probe = new UploadsProbeFiles($folder);

    expect($probe->plant($name, 'x'))->toBeFalse();
    $probe->remove($name);

    expect(file_get_contents($folder.'/'.basename($name)))->toBe('keep me');
})->with([
    'another name' => 'index.php',
    'too short' => 'hornet-probe-0123456789abcde.php',
    'not hexadecimal' => 'hornet-probe-0123456789abcdeg.php',
    'something before' => 'xhornet-probe-0123456789abcdef.php',
    'something after' => 'hornet-probe-0123456789abcdef.php.bak',
    'a line break after' => "hornet-probe-0123456789abcdef.php\n",
]);

it('sweeps what an earlier run left, and only what holds its mark', function (): void {
    $folder = mutAppFolder();
    $mine = "<?php echo strrev('abc');\n";
    mutAppWrite($folder.'/hornet-probe-aaaaaaaaaaaaaaaa.php', $mine);
    mutAppWrite($folder.'/hornet-probe-bbbbbbbbbbbbbbbb.php', 'not mine');
    mutAppWrite($folder.'/hornet-probe-other.php', $mine);
    mutAppWrite($folder.'/hornet-probe-cccccccccccccccc.php', ' '.$mine);

    expect((new UploadsProbeFiles($folder))->plant('hornet-probe-dddddddddddddddd.php', $mine))->toBeTrue()
        ->and(array_map(basename(...), glob($folder.'/*')))->toBe(['hornet-probe-bbbbbbbbbbbbbbbb.php', 'hornet-probe-cccccccccccccccc.php', 'hornet-probe-dddddddddddddddd.php', 'hornet-probe-other.php']);

    (new UploadsProbeFiles($folder))->remove('hornet-probe-dddddddddddddddd.php');

    expect(is_file($folder.'/hornet-probe-dddddddddddddddd.php'))->toBeFalse()
        ->and((new UploadsProbeFiles($folder.'/nope'))->plant('hornet-probe-dddddddddddddddd.php', 'x'))->toBeFalse();
});

// The visitor

it('believes a forwarding header only from a listed proxy', function (): void {
    $proxies = new TrustedProxies([Cidr::parse('t', '10.0.0.1')], 'X-Forwarded-For');

    expect(ClientAddress::fromServer(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], $proxies)?->address)->toBe('203.0.113.9')
        ->and(ClientAddress::fromServer(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], $proxies)?->address)->toBe('198.51.100.1')
        ->and(ClientAddress::fromServer(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => ['x']], $proxies)?->address)->toBe('10.0.0.1')
        ->and(ClientAddress::fromServer(['REMOTE_ADDR' => 'nonsense'], $proxies))->toBeNull()
        ->and(ClientAddress::fromServer(['REMOTE_ADDR' => 5], $proxies))->toBeNull()
        ->and(ClientAddress::fromServer([], $proxies))->toBeNull();
});

// Composer packages

/**
 * @param  array<mixed>|string|null  $installed
 * @param  array<mixed>|null  $lock
 */
function mutAppVendor(array|string|null $installed, ?array $lock = null): InstalledComposerPackages
{
    $project = mutAppFolder();
    mkdir($project.'/vendor/composer', 0777, true);
    $installed === null || file_put_contents($project.'/vendor/composer/installed.json', is_string($installed) ? $installed : json_encode($installed));
    $lock === null || file_put_contents($project.'/composer.lock', json_encode($lock));

    return new InstalledComposerPackages($project.'/vendor');
}

/**
 * @param  array<string, mixed>  $more
 * @return array<string, mixed>
 */
function mutAppEntry(string $name, mixed $version = '1.0.0', array $more = []): array
{
    return ['name' => $name, 'version' => $version, 'notification-url' => 'https://packagist.org/downloads/', ...$more];
}

it('asks only about packages of Packagist at a release', function (): void {
    $inventory = mutAppVendor(['packages' => [
        'junk',
        ['version' => '1'],
        mutAppEntry('a/one', 'v1.2.3'),
        mutAppEntry('a/dev-tool', '2.0.0-beta1'),
        mutAppEntry('a/branch', 'dev-main'),
        mutAppEntry('a/alias', '2.x-dev'),
        mutAppEntry('a/none', null),
        mutAppEntry('a/word', 'vnext'),
        mutAppEntry('a/trailing', '1.0 and more'),
        mutAppEntry('a/leading', 'x1.0'),
        mutAppEntry('private/pkg', '1.0.0', ['notification-url' => 'https://private.example/downloads/']),
        mutAppEntry('local/path', '1.0.0', ['dist' => ['type' => 'path']]),
        mutAppEntry('local/artifact', '1.0.0', ['source' => ['type' => 'artifact']]),
        mutAppEntry('a/zip', '1.0.0', ['dist' => ['type' => 'zip']]),
        mutAppEntry('Not A Name'),
        mutAppEntry("a/name\n"),
        mutAppEntry('x a/b'),
    ], 'dev-package-names' => ['a/dev-tool', 5]])->inventory();

    expect(array_map(static fn (ComposerPackage $package): array => [$package->name, $package->version, $package->dev], $inventory->packages))->toBe([['a/one', '1.2.3', false], ['a/dev-tool', '2.0.0-beta1', true], ['a/zip', '1.0.0', false]])
        ->and($inventory->branches)->toBe(['a/branch', 'a/alias', 'a/none', 'a/word', 'a/trailing', 'a/leading'])
        ->and($inventory->elsewhere)->toBe(6);
});

it('falls back on the lock file, and knows nothing without either', function (): void {
    $lock = ['packages' => [mutAppEntry('a/one')]];

    expect(mutAppVendor(null, $lock)->inventory()->packages)->toHaveCount(1)
        ->and(mutAppVendor('not json', $lock)->inventory()->packages)->toHaveCount(1)
        ->and(mutAppVendor(['packages' => 'x'], $lock)->inventory()->packages)->toHaveCount(1)
        ->and(mutAppVendor(null)->inventory())->toBeNull()
        ->and(mutAppVendor(null, ['packages' => 'x'])->inventory())->toBeNull()
        ->and((new InstalledComposerPackages(null))->inventory())->toBeNull();
});

it('reads a list of packages with no development list', function (): void {
    expect(mutAppVendor(['packages' => [mutAppEntry('a/one')], 'dev-package-names' => 'x'])->inventory()->packages[0]->dev)->toBeFalse();
});

it('finds the vendor folder of the project that loads it', function (): void {
    expect(InstalledComposerPackages::ofProject()->inventory())->not->toBeNull();
});

// The OSV database

/**
 * @param  list<mixed>  $answers  bodies, or responses
 * @return array{OsvAdvisoryDatabase, FakeHttpClient}
 */
function mutAppOsv(array $answers): array
{
    $http = new FakeHttpClient;
    $http->answers = array_map(static fn (mixed $answer): HttpResponse => $answer instanceof HttpResponse ? $answer : new HttpResponse(200, null, is_string($answer) ? $answer : json_encode($answer)), $answers);

    return [new OsvAdvisoryDatabase($http), $http];
}

/**
 * @return list<ComposerPackage>
 */
function mutAppTwo(): array
{
    return [new ComposerPackage('a/one', '1.0.0', false), new ComposerPackage('a/two', '2.0.0', false)];
}

it('sends the names and versions to the one address, and nothing else, within limits', function (): void {
    [$database, $http] = mutAppOsv([['results' => [[], []]]]);

    $found = $database->affecting(mutAppTwo());

    expect($found)->toBe([[], []])
        ->and($http->posts)->toHaveCount(1)
        ->and($http->posts[0]['url'])->toBe('https://api.osv.dev/v1/querybatch')
        ->and($http->posts[0]['headers'])->toBe(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
        ->and($http->posts[0]['private'])->toBeFalse()
        ->and($http->posts[0]['body'])->toBe('{"queries":[{"package":{"ecosystem":"Packagist","name":"a/one"},"version":"1.0.0"},{"package":{"ecosystem":"Packagist","name":"a/two"},"version":"2.0.0"}]}')
        ->and($http->limits[0])->toBe(['maxBytes' => 8 * 1024 * 1024, 'timeout' => 15]);
});

it('asks for the next page of the packages that have one only, and puts the records with their package', function (): void {
    [$database, $http] = mutAppOsv([
        ['results' => [['vulns' => [['id' => 'AAA-1', 'modified' => 'm1']]], ['vulns' => [['id' => 'BBB-1']], 'next_page_token' => 'p2']]],
        ['results' => [['vulns' => [['id' => 'BBB-2', 'modified' => 5]], 'next_page_token' => '']]],
    ]);

    expect($database->affecting(mutAppTwo()))->toBe([['AAA-1' => 'm1'], ['BBB-1' => '', 'BBB-2' => '']])
        ->and(json_decode($http->posts[1]['body'], true))->toBe(['queries' => [['package' => ['ecosystem' => 'Packagist', 'name' => 'a/two'], 'version' => '2.0.0', 'page_token' => 'p2']]]);
});

it('reads ten pages and no more', function (int $pages, bool $throws): void {
    $answers = array_fill(0, $pages - 1, ['results' => [['next_page_token' => 'more']]]);
    [$database, $http] = mutAppOsv([...$answers, ['results' => [[]]]]);

    $read = static fn (): array => $database->affecting([mutAppTwo()[0]]);

    if ($throws) {
        expect($read)->toThrow(TransportException::class, 'more pages');
    } else {
        expect($read())->toBe([[]]);
    }

    expect($http->posts)->toHaveCount(min($pages, 10));
})->with([[10, false], [11, true]]);

it('takes an answer that does not have the shape for no answer', function (mixed $answer): void {
    [$database] = mutAppOsv([$answer]);

    expect(fn () => $database->affecting(mutAppTwo()))->toThrow(TransportException::class);
})->with([
    'an error status' => [new HttpResponse(500, null, '{"results":[[],[]]}')],
    'not json' => ['nope'],
    'no results' => [['x' => 1]],
    'too few' => [['results' => [[]]]],
    'too many' => [['results' => [[], [], []]]],
    'a result that is not one' => [['results' => ['x', []]]],
    'a missing place' => [['results' => ['a' => [], 'b' => []]]],
    'vulns that are not a list' => [['results' => [['vulns' => 'x'], []]]],
    'a record that is not one' => [['results' => [['vulns' => ['x']], []]]],
    'a record without an id' => [['results' => [['vulns' => [['modified' => 'x']]], []]]],
    'an id with a path' => [['results' => [['vulns' => [['id' => '../etc']]], []]]],
    'an id too short' => [['results' => [['vulns' => [['id' => 'ab']]], []]]],
]);

it('refuses to fetch what is not an identifier, before any request', function (string $id): void {
    [$database, $http] = mutAppOsv([]);

    expect(fn () => $database->advisory($id, ''))->toThrow(TransportException::class)->and($http->gets)->toBe([]);
})->with(['../x', 'a', "GHSA-abc\n", 'GHSA/abc', str_repeat('a', 82)]);

it('reads a record, cleaning what it writes, and keeps only what it understands', function (): void {
    [$database, $http] = mutAppOsv([[
        'id' => 'GHSA-abcd',
        'aliases' => ['CVE-2026-0001', '../x', 5],
        'summary' => "Bad\r\nthing ".str_repeat('x', 300),
        'database_specific' => ['severity' => 'critical'],
        'affected' => [
            'junk',
            ['package' => ['ecosystem' => 'npm', 'name' => 'a/one'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0']]]]],
            ['package' => ['ecosystem' => 'Packagist', 'name' => 5], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0']]]]],
            ['package' => ['ecosystem' => 'Packagist', 'name' => 'a/one'], 'ranges' => [
                'junk',
                ['type' => 'GIT', 'events' => [['introduced' => '0']]],
                ['type' => 'ECOSYSTEM', 'events' => 'x'],
                ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], 'junk', ['fixed' => 'v1.2.0'], ['last_affected' => '1.1.0'], ['fixed' => 'x1'], ['fixed' => "1.0\n"], ['fixed' => '1.0 x'], ['limit' => '2.0']]],
            ]],
        ],
    ]]);

    $advisory = $database->advisory('GHSA-abcd', 'm');

    expect($http->gets[0])->toBe(['url' => 'https://api.osv.dev/v1/vulns/GHSA-abcd', 'headers' => ['Accept' => 'application/json'], 'maxBytes' => 8 * 1024 * 1024, 'timeout' => 15, 'private' => false])
        ->and($advisory->aliases)->toBe(['CVE-2026-0001'])
        ->and($advisory->summary)->toStartWith('Bad thing xxx')
        ->and(mb_strlen($advisory->summary))->toBe(160)
        ->and($advisory->severity)->toBe(Severity::Critical)
        ->and($advisory->ranges)->toBe([['package' => 'a/one', 'events' => [['introduced', '0'], ['fixed', '1.2.0'], ['last_affected', '1.1.0']]]])
        ->and($advisory->withdrawn)->toBeFalse();
});

it('takes the word of severity, and medium when there is none, never low', function (mixed $level, Severity $severity): void {
    [$database] = mutAppOsv([['id' => 'AAA-1', 'database_specific' => ['severity' => $level]]]);

    expect($database->advisory('AAA-1', '')->severity)->toBe($severity);
})->with([['CRITICAL', Severity::Critical], ['high', Severity::High], ['Low', Severity::Low], ['MODERATE', Severity::Medium], [null, Severity::Medium], [5, Severity::Medium]]);

it('reads a bare record, and one that was withdrawn', function (): void {
    [$database] = mutAppOsv([['id' => 'AAA-1', 'aliases' => 'x', 'summary' => 5, 'database_specific' => 'x', 'affected' => 'x', 'withdrawn' => '2026-01-01']]);

    $advisory = $database->advisory('AAA-1', '');

    expect([$advisory->aliases, $advisory->summary, $advisory->severity, $advisory->ranges, $advisory->withdrawn])->toBe([[], '', Severity::Medium, [], true]);
});

it('refuses another record than the one asked', function (): void {
    [$database] = mutAppOsv([['id' => 'AAA-2']]);

    expect(fn () => $database->advisory('AAA-1', ''))->toThrow(TransportException::class, 'another record');
});
