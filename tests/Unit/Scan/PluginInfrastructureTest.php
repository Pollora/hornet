<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Infrastructure\WordPress\WordPressPluginChecksums;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;

function pluginAnswer(HttpResponse $response): FakeHttpClient
{
    $http = new FakeHttpClient;
    $http->answers = [$response];

    return $http;
}

it('asks for the slug and the version and nothing else, and reads one hash or several', function (): void {
    $sha = hash('sha256', 'a');
    $other = hash('sha256', 'b');
    $http = pluginAnswer(new HttpResponse(200, null, (string) json_encode(['plugin' => 'akismet', 'files' => [
        'akismet.php' => ['md5' => 'x', 'sha256' => $sha],
        'readme.txt' => ['md5' => ['x'], 'sha256' => [$sha, $other, 'not a hash', 4]],
        'bad.php' => ['md5' => 'x'],
        '../escape.php' => ['sha256' => $sha],
    ]])));

    $list = (new WordPressPluginChecksums($http))->checksums('akismet', '5.3.1+beta');

    expect($list)->toBe(['akismet.php' => [$sha], 'readme.txt' => [$sha, $other]])
        ->and($http->gets[0]['url'])->toBe('https://downloads.wordpress.org/plugin-checksums/akismet/5.3.1%2Bbeta.json');
});

it('has no list for a version WordPress.org never had: 404 is not an error', function (): void {
    expect((new WordPressPluginChecksums(pluginAnswer(new HttpResponse(404, null, 'Not found'))))->checksums('premium', '2.0'))->toBeNull();
});

it('does not put a name that is not a plain one in an address', function (string $slug, string $version): void {
    $http = new FakeHttpClient;

    expect((new WordPressPluginChecksums($http))->checksums($slug, $version))->toBeNull()->and($http->gets)->toBe([]);
})->with([
    ['../etc', '1.0'],
    ['akismet/../x', '1.0'],
    ['akismet', '1.0/../../x'],
    ['akismet?x=1', '1.0'],
    ['', '1.0'],
    ['akismet', ''],
    ["akismet\n", '1.0'],
]);

it('is an error when the answer is not the list', function (HttpResponse $answer, string $message): void {
    expect(fn () => (new WordPressPluginChecksums(pluginAnswer($answer)))->checksums('akismet', '5.3'))->toThrow(TransportException::class, $message);
})->with([
    'a server error' => [new HttpResponse(503), 'HTTP 503'],
    'not JSON, or cut short' => [new HttpResponse(200, null, '{"files":{"a.php"'), 'not a list'],
    'no files' => [new HttpResponse(200, null, '{"files":{}}'), 'not a list'],
    'files without a usable hash' => [new HttpResponse(200, null, '{"files":{"a.php":{"md5":"x"}}}'), 'without usable'],
]);

it('reads back a baseline, and leaves out what does not fit', function (): void {
    $baseline = new Baseline('2.0', ['a.php' => 'hash']);

    expect(Baseline::fromArray($baseline->toArray()))->toEqual($baseline)
        ->and(Baseline::fromArray(['version' => '1', 'hashes' => ['a.php' => 'h', 'b.php' => 4]])?->hashes)->toBe(['a.php' => 'h'])
        ->and(Baseline::fromArray('text'))->toBeNull()
        ->and(Baseline::fromArray(['version' => 1, 'hashes' => []]))->toBeNull()
        ->and(Baseline::fromArray(['version' => '1']))->toBeNull();
});
