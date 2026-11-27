<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\ContentReadException;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\ContentKind;
use Pollora\Hornet\Domain\Scan\ContentRules;
use Pollora\Hornet\Domain\Scan\Software;
use Pollora\Hornet\Infrastructure\WordPress\WordPressCoreChecksums;
use Pollora\Hornet\Infrastructure\WordPress\WordPressPluginChecksums;
use Pollora\Hornet\Infrastructure\WordPress\WpContentStore;
use Pollora\Hornet\Infrastructure\WordPress\WpHttpClient;
use Pollora\Hornet\Infrastructure\WordPress\WpPluginDirectory;
use Pollora\Hornet\Infrastructure\WordPress\WpVulnerabilityFeed;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;

function mutWpHttp(int $status, mixed $body): FakeHttpClient
{
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse($status, null, is_string($body) ? $body : (string) json_encode($body))];

    return $http;
}

/** @param array<string, mixed> $entry */
function mutWpVulnerability(array $entry, string $version = '3.0'): array
{
    return (new WpVulnerabilityFeed(mutWpHttp(200, ['error' => 0, 'data' => ['vulnerability' => [$entry]]])))->of(Software::Plugin, 'akismet', $version);
}

it('asks the vulnerability feed for JSON, with twenty seconds and four megabytes', function (): void {
    $http = mutWpHttp(200, ['error' => 0, 'data' => []]);
    (new WpVulnerabilityFeed($http))->of(Software::Plugin, 'akismet', '3.0');

    expect($http->gets[0])->toMatchArray(['headers' => ['Accept' => 'application/json'], 'maxBytes' => 4194304, 'timeout' => 20]);
});

it('takes an answer for a feed only when it says there is no error', function (array $answer): void {
    expect(fn () => (new WpVulnerabilityFeed(mutWpHttp(200, $answer)))->of(Software::Plugin, 'akismet', '3.0'))->toThrow(TransportException::class, 'not a list');
})->with([
    'no word of an error' => [['data' => []]],
    'an error with data' => [['error' => 1, 'data' => []]],
]);

it('follows the operators of the range as the feed gives them', function (array $operator, int $found): void {
    expect(mutWpVulnerability(['operator' => $operator]))->toHaveCount($found);
})->with([
    'from this version, excluded' => [['min_version' => '3.0', 'min_operator' => 'gt'], 0],
    'from this version, included by default' => [['min_version' => '3.0'], 1],
    'up to this version, included' => [['max_version' => '3.0', 'max_operator' => 'le'], 1],
    'up to this version, excluded by default' => [['max_version' => '3.0'], 0],
]);

it('reads an entry that says little', function (): void {
    $found = mutWpVulnerability([])[0];

    expect([$found->id, $found->title, $found->severity, $found->fixedIn, $found->unfixed, $found->link])
        ->toBe(['no identifier', 'A vulnerability that the feed does not describe', Severity::Medium, null, false, null]);
});

it('cuts a long title and a long link where it says', function (): void {
    $link = static fn (int $length): string => 'https://x.example/'.str_repeat('a', $length - 18);

    expect(mb_strlen(mutWpVulnerability(['name' => str_repeat('x', 400)])[0]->title))->toBe(160)
        ->and(mutWpVulnerability(['source' => ['not a source', ['link' => $link(300)]]])[0]->link)->toBe($link(300))
        ->and(mutWpVulnerability(['source' => [['link' => $link(301)]]])[0]->link)->toBeNull();
});

it('takes the newer score first, and any score from 0 to 10', function (array $impact, Severity $severity): void {
    expect(mutWpVulnerability(['impact' => $impact])[0]->severity)->toBe($severity);
})->with([
    'the newer scale wins' => [['cvss3' => ['score' => '9.9'], 'cvss' => ['score' => '1.0']], Severity::Critical],
    'ten is a score' => [['cvss3' => ['score' => 10]], Severity::Critical],
    'zero is a score' => [['cvss3' => ['score' => 0]], Severity::Low],
    'below zero is none' => [['cvss3' => ['score' => -0.5]], Severity::Medium],
    'above ten is none' => [['cvss3' => ['score' => 10.5]], Severity::Medium],
]);

it('asks the directory for one plugin and none of its long texts', function (): void {
    $http = mutWpHttp(200, ['name' => 'A', 'slug' => 'a']);
    (new WpPluginDirectory($http))->of('a');
    $fields = implode('', array_map(static fn (string $field): string => '&request%5Bfields%5D%5B'.$field.'%5D=0', ['sections', 'description', 'short_description', 'versions', 'reviews', 'ratings', 'banners', 'icons', 'screenshots', 'contributors', 'tags']));

    expect($http->gets[0]['url'])->toBe('https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=a'.$fields)
        ->and($http->gets[0]['headers'])->toBe(['Accept' => 'application/json'])
        ->and($http->gets[0]['maxBytes'])->toBe(524288);
});

it('takes no answer of the directory for a listing unless it plainly is one', function (int $status, array $body): void {
    expect(fn () => (new WpPluginDirectory(mutWpHttp($status, $body)))->of('a'))->toThrow(TransportException::class);
})->with([
    'a server error that says closed' => [500, ['closed' => true, 'name' => 'A']],
    'a 404 with a listing' => [404, ['name' => 'A', 'slug' => 'a']],
    'a listing with an error' => [200, ['name' => 'A', 'slug' => 'a', 'error' => 'x']],
    'no name' => [200, ['slug' => 'a']],
]);

it('keeps only a version and a date that are ones', function (mixed $version, mixed $updated, ?string $expected, ?int $date): void {
    $listing = (new WpPluginDirectory(mutWpHttp(200, ['name' => 'A', 'slug' => 'a', 'version' => $version, 'last_updated' => $updated])))->of('a');

    expect([$listing->version, $listing->updatedAt])->toBe([$expected, $date]);
})->with([
    'both' => ['1.2.3', '2026-08-18 11:42pm GMT', '1.2.3', 1787011200],
    'neither' => ['1 2', '2026-02-30 11:42pm GMT', null, null],
    'not text' => [['1'], ['x'], null, null],
]);

it('asks for checksums in JSON, with thirty seconds', function (): void {
    $core = mutWpHttp(200, ['checksums' => ['123' => 'abc', 'wp-load.php' => 'def', 'odd' => 5]]);
    $plugin = mutWpHttp(200, ['files' => ['123' => ['sha256' => ['nope', str_repeat('a', 64)]]]]);

    expect((new WordPressCoreChecksums($core))->checksums('7.1', 'en_US'))->toBe([123 => 'abc', 'wp-load.php' => 'def'])
        ->and((new WordPressPluginChecksums($plugin))->checksums('a', '1.0'))->toBe([123 => [str_repeat('a', 64)]])
        ->and($core->gets[0])->toMatchArray(['headers' => ['Accept' => 'application/json'], 'maxBytes' => 4194304, 'timeout' => 30])
        ->and($plugin->gets[0])->toMatchArray(['headers' => ['Accept' => 'application/json'], 'maxBytes' => 8388608, 'timeout' => 30]);
});

it('says that an answer without files is not a list of checksums', function (): void {
    expect(fn () => (new WordPressPluginChecksums(mutWpHttp(200, ['plugin' => 'a'])))->checksums('a', '1.0'))
        ->toThrow(TransportException::class, 'not a list of checksums');
});

it('reads the headers of an answer as text, whatever their names', function (): void {
    Functions\when('wp_safe_remote_get')->justReturn([]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_headers')->justReturn(['7' => 'seven', 'X-Many' => ['a', 5, null]]);
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('');

    expect((new WpHttpClient)->get(Secret::of('https://example.com'), [], 10)->headers)->toBe([7 => 'seven', 'x-many' => 'a, 5, ']);
});

/** A store on a database that keeps the queries it is asked. */
function mutWpStore(array $answers): array
{
    $database = new FakeWpdb;
    $database->answers = $answers;

    return [new WpContentStore($database), $database];
}

it('looks for each mark in the column of each source, leaving out what is not shown', function (string $source, string $table, string $key, string $column, string $where): void {
    [$store, $database] = mutWpStore([['4', '9']]);

    expect($store->matches($source, 1, 100))->toBe([4, 9]);

    $like = implode(' OR ', array_map(static fn (string $mark): string => "CONVERT(CAST(`{$column}` AS BINARY) USING latin1) LIKE '%".addslashes(addcslashes($mark, '_%\\'))."%'", ContentRules::MARKS));

    expect($database->ran[0])->toStartWith("SELECT `{$key}` FROM `{$table}` WHERE `{$key}` BETWEEN 1 AND 100")
        ->and($database->ran[0])->toEndWith(" AND ({$like})")
        ->and($database->ran[0])->toContain($where);
})->with([
    ['posts', 'wp_posts', 'ID', 'post_content', "AND post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft')"],
    ['comments', 'wp_comments', 'comment_ID', 'comment_content', "AND comment_approved = '1'"],
    ['users', 'wp_users', 'ID', 'user_url', 'BETWEEN 1 AND 100 AND (CONVERT'],
    ['options', 'wp_options', 'option_id', 'option_value', 'NOT LIKE'],
]);

it('reads the entries it is given, the start and the end of a long one, named as the source names them', function (string $source, string $table, string $key, string $column, string $name, ContentKind $kind): void {
    [$store, $database] = mutWpStore([[(object) ['id' => '4', 'name' => 'blogname', 'body' => 'text']]]);

    $entry = $store->entries($source, [4, 9])[0];

    expect($database->ran[0])->toBe("SELECT `{$key}` AS id, `{$name}` AS name, IF(CHAR_LENGTH(`{$column}`) <= 262144, `{$column}`, CONCAT(LEFT(`{$column}`, 131072), RIGHT(`{$column}`, 131072))) AS body FROM `{$table}` WHERE `{$key}` IN (4,9)")
        ->and([$entry->kind, $entry->id, $entry->text])->toBe([$kind, 4, 'text']);
})->with([
    ['posts', 'wp_posts', 'ID', 'post_content', 'ID', ContentKind::Post],
    ['comments', 'wp_comments', 'comment_ID', 'comment_content', 'comment_ID', ContentKind::Comment],
    ['users', 'wp_users', 'ID', 'user_url', 'ID', ContentKind::Profile],
    ['options', 'wp_options', 'option_id', 'option_value', 'option_name', ContentKind::SiteIdentity],
]);

it('does not call a row it cannot read clean', function (): void {
    [$store] = mutWpStore([[(object) ['id' => null]]]);

    expect(fn () => $store->entries('posts', [1]))->toThrow(ContentReadException::class);
});
