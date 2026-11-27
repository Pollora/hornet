<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\ContentReadException;
use Pollora\Hornet\Domain\Exceptions\DatabaseUnavailableException;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\WpContentStore;
use Pollora\Hornet\Tests\Unit\Malformed\WpdbStandIn;

/*
 * What `$wpdb` hands back is not always a row of the shape that was asked for.
 */

defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

if (! class_exists('wpdb', false)) {
    class_alias(WpdbStandIn::class, 'wpdb');
}

/**
 * A `$wpdb` that answers the same lines to every question.
 *
 * @param  list<mixed>  $rows
 */
function damagedDatabase(array $rows): object
{
    $database = Mockery::mock('wpdb');
    $database->shouldReceive('prepare')->andReturn('SELECT 1');
    $database->shouldReceive('get_results')->andReturn($rows);

    return $database;
}

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

it('gives only the details an event may hold, whatever the table says, and skips a line that is not one', function (): void {
    $GLOBALS['wpdb'] = damagedDatabase([
        'not a line',
        ['id' => '7', 'occurred_at' => '2026-11-27 10:00:00', 'type' => 'login.failed', 'source' => 'web', 'user_id' => ['x'], 'details' => '{"ratio":1.5,"from":"editor","nonce":"x","0":"first","count":2}'],
    ]);

    $events = (new TableJournal)->find(new JournalQuery);

    expect($events)->toHaveCount(1)
        ->and($events[0]->details)->toBe(['from' => 'editor', 'count' => 2])
        ->and($events[0]->userId)->toBe(0)
        ->and($events[0]->id)->toBe(7);
});

it('does not take a missing database connection for an empty journal', function (): void {
    unset($GLOBALS['wpdb']);

    expect(fn () => (new TableJournal)->find(new JournalQuery))->toThrow(DatabaseUnavailableException::class);
});

it('removes nothing when the query cannot be prepared', function (): void {
    $database = Mockery::mock('wpdb');
    $database->shouldReceive('prepare')->andReturnNull();
    $database->shouldNotReceive('query');
    $GLOBALS['wpdb'] = $database;

    expect((new TableJournal)->anonymizeUser(3))->toBe(0);
});

it('does not call a table clean when one of its lines cannot be read', function (mixed $row): void {
    $store = new WpContentStore(damagedDatabase([$row]));

    expect(fn () => $store->entries('posts', [1]))->toThrow(ContentReadException::class);
})->with([
    'not a line' => ['text'],
    'no usable id' => [(object) ['id' => null, 'name' => '1', 'body' => '<script></script>']],
]);
