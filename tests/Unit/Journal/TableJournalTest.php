<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Exceptions\JournalException;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalSchema;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;

beforeEach(function (): void {
    $this->site = FakeSite::install();
    $this->db = $GLOBALS['wpdb'] = new FakeWpdb;
});

/**
 * @param  array<string, mixed>  $change
 * @return array<string, mixed>
 */
function journalRow(array $change = []): array
{
    return [...['id' => '12', 'occurred_at' => '2026-11-25 10:00:00', 'type' => 'login.failed', 'source' => 'web', 'user_id' => '3', 'actor' => 'ana', 'subject' => 'user:3', 'ip' => '203.0.113.0', 'details' => '{"reason":"incorrect_password","count":2,"nested":{"a":1}}'], ...$change];
}

it('writes an event through insert(), in UTC, with a format for every column', function (): void {
    (new TableJournal)->record(new Event(new DateTimeImmutable('2026-11-25 12:00:00 +02:00'), 'login.success', Source::Admin, 3, 'ana', 'user:3', '203.0.113.0', ['path' => '/a/b']));

    expect($this->db->inserted)->toBe([[
        'table' => 'wp_hornet_journal',
        'data' => ['occurred_at' => '2026-11-25 10:00:00', 'type' => 'login.success', 'source' => 'admin', 'user_id' => 3, 'actor' => 'ana', 'subject' => 'user:3', 'ip' => '203.0.113.0', 'details' => '{"path":"/a/b"}'],
        'format' => ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
    ]]);
});

it('says so when the database does not take the event', function (): void {
    $this->db->insertAnswer = false;

    (new TableJournal)->record(new Event(new DateTimeImmutable, 'login.success', Source::Web));
})->throws(JournalException::class, 'could not be written');

it('reads rows back into events, newest first, and keeps only flat details', function (): void {
    $this->db->answers = [[journalRow()]];

    $events = (new TableJournal)->find(new JournalQuery);

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray())->toBe(['id' => 12, 'at' => (new DateTimeImmutable('2026-11-25 10:00:00 UTC'))->getTimestamp(), 'type' => 'login.failed', 'source' => 'web', 'user_id' => 3, 'actor' => 'ana', 'subject' => 'user:3', 'ip' => '203.0.113.0', 'details' => ['reason' => 'incorrect_password', 'count' => 2]])
        ->and($this->db->ran)->toBe(['SELECT * FROM `wp_hornet_journal`  ORDER BY occurred_at DESC, id DESC LIMIT 50 OFFSET 0']);
});

it('puts every condition through the placeholders, a group of types as a prefix', function (): void {
    $since = new DateTimeImmutable('2026-11-01 02:00:00 +02:00');
    $this->db->answers = [[], []];
    $journal = new TableJournal;
    $journal->find(new JournalQuery('login.', Source::Cli, 3, $since, 10, 20));
    $journal->find(new JournalQuery("x' OR '1'='1"));

    expect($this->db->ran[0])->toBe("SELECT * FROM `wp_hornet_journal` WHERE type LIKE 'login.%' AND source = 'cli' AND user_id = 3 AND occurred_at >= '2026-11-01 00:00:00' ORDER BY occurred_at DESC, id DESC LIMIT 10 OFFSET 20")
        ->and($this->db->ran[1])->toContain("WHERE type = 'x\\' OR \\'1\\'=\\'1' ORDER BY");
});

it('escapes the wildcards of a group, so that it matches itself only', function (): void {
    $this->db->answers = [[]];
    (new TableJournal)->find(new JournalQuery('lo_in.'));

    expect($this->db->ran[0])->toContain("LIKE 'lo\\\\_in.%'");
});

it('asks for every type when none is named', function (?string $type): void {
    $this->db->answers = [[]];
    (new TableJournal)->find(new JournalQuery($type, userId: 0));

    expect($this->db->ran[0])->toContain('FROM `wp_hornet_journal` WHERE user_id = 0 ORDER BY');
})->with([null, '']);

it('leaves out a row that cannot be an event', function (array $change): void {
    $this->db->answers = [[journalRow($change), journalRow(['id' => '13'])]];

    expect(array_map(static fn (Event $event): ?int => $event->id, (new TableJournal)->find(new JournalQuery)))->toBe([13]);
})->with([
    'an unknown source' => [['source' => 'mars']],
    'no source' => [['source' => null]],
    'no time' => [['occurred_at' => null]],
    'no type' => [['type' => null]],
]);

it('keeps an event whose other columns are damaged, with them empty', function (?string $details): void {
    $this->db->answers = [[journalRow(['details' => $details, 'actor' => null, 'subject' => null, 'ip' => null, 'user_id' => null, 'id' => null])]];

    $event = (new TableJournal)->find(new JournalQuery)[0];

    expect([$event->details, $event->actor, $event->subject, $event->ip, $event->userId, $event->id])->toBe([[], '', '', '', 0, 0]);
})->with([null, '{broken', '"text"', '{"a":{"b":{"c":{"d":{"e":1}}}}}']);

it('has no event, and does not fail, when the query fails', function (): void {
    $this->db->answers = [null, null, null, false];
    $journal = new TableJournal;

    expect($journal->find(new JournalQuery))->toBe([])
        ->and($journal->count(new JournalQuery))->toBe(0)
        ->and($journal->aboutUser(3, 10))->toBe([])
        ->and($journal->purgeBefore(new DateTimeImmutable))->toBe(0);
});

it('counts with the same conditions', function (): void {
    $this->db->answers = ['41'];

    expect((new TableJournal)->count(new JournalQuery(userId: 3)))->toBe(41)
        ->and($this->db->ran[0])->toBe('SELECT COUNT(*) FROM `wp_hornet_journal` WHERE user_id = 3');
});

it('removes what is older than the cutoff, given in UTC, and says how many', function (): void {
    $this->db->answers = [7];

    expect((new TableJournal)->purgeBefore(new DateTimeImmutable('2026-11-01 02:00:00 +02:00')))->toBe(7)
        ->and($this->db->ran[0])->toBe("DELETE FROM `wp_hornet_journal` WHERE occurred_at < '2026-11-01 00:00:00'");
});

it('finds what a person did and what was done to them, a page at a time', function (): void {
    $this->db->answers = [[journalRow()], []];
    $journal = new TableJournal;

    expect($journal->aboutUser(3, 100, 200))->toHaveCount(1);
    $journal->aboutUser(3, 0, -4);

    expect($this->db->ran[0])->toBe("SELECT * FROM `wp_hornet_journal` WHERE user_id = 3 OR subject = 'user:3' ORDER BY occurred_at DESC, id DESC LIMIT 100 OFFSET 200")
        ->and($this->db->ran[1])->toContain('LIMIT 1 OFFSET 0');
});

it('takes a person out of the events that name them, and keeps the events', function (): void {
    $this->db->answers = [2, 3];

    expect((new TableJournal)->anonymizeUser(3))->toBe(5)
        ->and($this->db->ran)->toBe([
            "UPDATE `wp_hornet_journal` SET user_id = 0, actor = '', ip = '' WHERE user_id = 3",
            "UPDATE `wp_hornet_journal` SET subject = 'user:erased' WHERE subject = 'user:3'",
        ]);
});

it('counts only what was changed when one of the two updates fails', function (mixed $by, mixed $to, int $total): void {
    $this->db->answers = [$by, $to];

    expect((new TableJournal)->anonymizeUser(3))->toBe($total);
})->with([[false, 3, 3], [2, false, 2], [false, false, 0]]);

describe('the table', function (): void {
    it('is made with dbDelta when it is missing or older, and its version noted', function (mixed $noted): void {
        if ($noted !== null) {
            $this->site->options[JournalSchema::OPTION] = $noted;
        }

        $statements = [];
        Functions\when('dbDelta')->alias(function (string $sql) use (&$statements): array {
            $statements[] = $sql;

            return [];
        });
        JournalSchema::ensure();

        expect($statements)->toHaveCount(1)
            ->and($statements[0])->toStartWith('CREATE TABLE wp_hornet_journal (')
            ->and($statements[0])->toContain('PRIMARY KEY  (id)', 'KEY occurred_at (occurred_at)', 'KEY subject (subject)', 'DEFAULT CHARACTER SET utf8mb4;')
            ->and($this->site->options[JournalSchema::OPTION])->toBe(JournalSchema::VERSION);
    })->with(['missing' => [null], 'older' => ['0']]);

    it('is left alone when it is up to date', function (): void {
        $this->site->options[JournalSchema::OPTION] = JournalSchema::VERSION;
        Functions\expect('dbDelta')->never();

        JournalSchema::ensure();
    });

    it('goes with its option when dropped, in the prefix of the site', function (): void {
        $this->db->prefix = 'blog_';
        $this->site->options[JournalSchema::OPTION] = '1';
        JournalSchema::drop();

        expect($this->db->ran)->toBe(['DROP TABLE IF EXISTS `blog_hornet_journal`'])->and($this->site->options)->toBe([]);
    });
});
