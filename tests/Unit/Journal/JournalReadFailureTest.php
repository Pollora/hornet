<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\JournalException;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;

/*
 * WordPress answers a refused query as it answers an empty table.
 */

beforeEach(function (): void {
    $this->site = FakeSite::install();
    $this->db = $GLOBALS['wpdb'] = new FakeWpdb;
    $this->db->failWith = "Table 'wp_hornet_journal' doesn't exist";
});

it('does not take a refused query for an empty journal', function (Closure $read): void {
    expect(fn () => $read(new TableJournal))->toThrow(JournalException::class, 'could not be read');
})->with([
    'the events' => fn (TableJournal $journal) => $journal->find(new JournalQuery),
    'their number' => fn (TableJournal $journal) => $journal->count(new JournalQuery),
    'those about a person' => fn (TableJournal $journal) => $journal->aboutUser(3, 10),
]);
