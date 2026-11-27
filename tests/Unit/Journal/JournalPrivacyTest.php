<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalPrivacy;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

beforeEach(function (): void {
    $user = FakeWpUser::named(3, 'ana');
    Functions\when('get_user_by')->alias(static fn (string $field, string $value): mixed => $field === 'email' && $value === 'ana@example.com' ? $user : false);
    $this->journal = new InMemoryJournal;
    $this->privacy = new JournalPrivacy($this->journal, 45);
});

function journalEvent(int $by, string $subject, ?int $id = 1): Event
{
    return new Event(new DateTimeImmutable('2026-11-25 10:00:00 UTC'), 'login.failed', Source::Web, $by, 'x', $subject, '198.51.100.0', [], $id);
}

it('registers an exporter and an eraser next to those of others, and a paragraph for the policy', function (): void {
    $hooks = new RecordingHooks;
    $this->privacy->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->toBe(['wp_privacy_personal_data_exporters', 'wp_privacy_personal_data_erasers', 'admin_init'])
        ->and(array_keys($this->privacy->exporters(['other' => 1])))->toBe(['other', 'hornet-journal'])
        ->and(array_keys($this->privacy->erasers(null)))->toBe(['hornet-journal'])
        ->and($this->privacy->exporters(null)['hornet-journal']['callback'])->toBeCallable()
        ->and($this->privacy->erasers([])['hornet-journal']['callback'])->toBeCallable();
});

it('gives the network and the source only of what the person did: of what was done to them, they are somebody else\'s', function (): void {
    $this->journal->events = [journalEvent(9, 'user:3', 8), journalEvent(3, 'user:3', 7)];

    $export = $this->privacy->export('ana@example.com');

    expect($export['done'])->toBeTrue()->and($export['data'])->toHaveCount(2)
        ->and($export['data'][0])->toBe(['group_id' => 'hornet-journal', 'group_label' => 'Security journal', 'item_id' => 'hornet-journal-8', 'data' => [
            ['name' => 'When', 'value' => '2026-11-25 10:00:00 UTC'], ['name' => 'What', 'value' => 'login.failed'], ['name' => 'About', 'value' => 'user:3'],
        ]])
        ->and(array_column($export['data'][1]['data'], 'value', 'name'))->toBe(['When' => '2026-11-25 10:00:00 UTC', 'What' => 'login.failed', 'About' => 'user:3', 'From' => 'web', 'Network' => '198.51.100.0']);
});

it('goes through the events a hundred at a time, and says when there are no more', function (): void {
    $this->journal->events = array_map(static fn (int $number): Event => journalEvent(3, 'user:3', $number), range(1, 150));

    $first = $this->privacy->export('ana@example.com', 0);
    $second = $this->privacy->export('ana@example.com', 2);

    expect($first['done'])->toBeFalse()->and($first['data'])->toHaveCount(100)->and($first['data'][0]['item_id'])->toBe('hornet-journal-1')
        ->and($second['done'])->toBeTrue()->and($second['data'])->toHaveCount(50)->and($second['data'][0]['item_id'])->toBe('hornet-journal-101');
});

it('gives nothing, and erases nothing, for an address that is nobody\'s', function (): void {
    $this->journal->events = [journalEvent(3, 'user:3')];

    expect($this->privacy->export('who@example.com'))->toBe(['data' => [], 'done' => true])
        ->and($this->privacy->erase('who@example.com'))->toBe(['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true])
        ->and($this->journal->events[0]->userId)->toBe(3);
});

it('takes the person out of the events, says that they were kept, and says nothing the second time', function (): void {
    $this->journal->events = [journalEvent(3, 'user:3')];

    $erased = $this->privacy->erase('ana@example.com');

    expect($erased['items_removed'])->toBeTrue()->and($erased['items_retained'])->toBeTrue()->and($erased['done'])->toBeTrue()
        ->and($erased['messages'][0])->toContain('were kept')
        ->and($this->journal->events[0]->userId)->toBe(0)
        ->and($this->journal->events[0]->ip)->toBe('')
        ->and($this->privacy->erase('ana@example.com')['messages'])->toBe([]);
});

it('says in the privacy policy how long the journal is kept', function (): void {
    Functions\when('wp_kses_post')->returnArg(1);
    $given = null;
    Functions\when('wp_add_privacy_policy_content')->alias(function (string $who, string $text) use (&$given): void {
        $given = [$who, $text];
    });

    $this->privacy->policy();

    expect($given[0])->toBe('Hornet (security)')->and($given[1])->toContain('<h2>Security journal</h2>', 'for 45 days', 'shortened');
});
