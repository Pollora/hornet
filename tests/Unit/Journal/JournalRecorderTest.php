<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeRequestContext;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;

function recorder(?FakeRequestContext $request = null, ?InMemoryJournal $journal = null): JournalRecorder
{
    return new JournalRecorder($journal ?? new InMemoryJournal, $request ?? new FakeRequestContext, new FakeClock('2026-11-25 10:00:00'));
}

it('writes what happened, from where, by whom, to what, with the address cut', function (): void {
    $journal = new InMemoryJournal;

    $written = recorder(journal: $journal)->record('user.role_changed', 'user:12', ['from' => 'editor', 'to' => 'administrator']);

    $event = $journal->events[0];

    expect($written)->toBeTrue()->and($event->type)->toBe('user.role_changed')->and($event->source)->toBe(Source::Admin)
        ->and($event->userId)->toBe(7)->and($event->actor)->toBe('olivier')->and($event->subject)->toBe('user:12')
        ->and($event->ip)->toBe('203.0.113.0')->and($event->details)->toBe(['from' => 'editor', 'to' => 'administrator'])
        ->and($event->at->format('c'))->toBe('2026-11-25T10:00:00+00:00');
});

it('says who logs in when that is not the person who is logged in', function (): void {
    $journal = new InMemoryJournal;

    recorder(new FakeRequestContext(Source::Web, 0, '', '198.51.100.9'), $journal)->record('login.success', 'user:3', [], 3, 'marie');
    recorder(journal: $journal)->record('user.created', 'user:9', [], 4);

    expect($journal->events[0])->toMatchObject(['userId' => 3, 'actor' => 'marie'])
        // Another account than the logged in one: the login is not guessed
        ->and($journal->events[1]->userId)->toBe(4)->and($journal->events[1]->actor)->toBe('');
});

it('knows nobody on the command line and the cron, and keeps no address there', function (): void {
    $journal = new InMemoryJournal;

    recorder(new FakeRequestContext(Source::Cli, 0, '', ''), $journal)->record('plugin.activated', 'plugin:akismet');

    expect($journal->events[0])->toMatchObject(['userId' => 0, 'actor' => '', 'ip' => ''])->and($journal->events[0]->source)->toBe(Source::Cli);
});

it('does not write a type that is not on the list', function (): void {
    $journal = new InMemoryJournal;

    expect(recorder(journal: $journal)->record('something.else', 'x'))->toBeFalse()->and($journal->events)->toBe([]);
});

it('cleans what it is given: no secret, no control character, a length', function (): void {
    $journal = new InMemoryJournal;

    recorder(new FakeRequestContext(login: "bad\nlogin".str_repeat('x', 100)), $journal)->record('login.failed', "user:1\x00".str_repeat('s', 300), ['password' => 'hunter2', 'reason' => 'incorrect_password']);

    $event = $journal->events[0];

    expect($event->details)->toBe(['reason' => 'incorrect_password'])->and(mb_strlen($event->subject))->toBeLessThanOrEqual(120)->and(mb_strlen($event->actor))->toBeLessThanOrEqual(60)
        ->and($event->subject)->not->toContain("\x00")->and($event->actor)->not->toContain("\n")->and(json_encode($event->toArray()))->not->toContain('hunter2');
});

it('never gets in the way: a journal that cannot be written is counted, not thrown', function (): void {
    $journal = new InMemoryJournal;
    $journal->failure = new RuntimeException('the table is gone');
    $recorder = recorder(journal: $journal);

    expect($recorder->record('login.success', 'user:1'))->toBeFalse()->and($recorder->record('login.success', 'user:1'))->toBeFalse()->and($recorder->failures())->toBe(2);
});

it('does not count a type it refused as a failure', function (): void {
    $recorder = recorder();

    $recorder->record('nope');

    expect($recorder->failures())->toBe(0);
});

it('purges what is older than a date, and says how many', function (): void {
    $journal = new InMemoryJournal;
    $old = new JournalRecorder($journal, new FakeRequestContext, new FakeClock('2026-08-01 10:00:00'));
    $new = new JournalRecorder($journal, new FakeRequestContext, new FakeClock('2026-11-20 10:00:00'));
    $old->record('login.success', 'user:1');
    $old->record('login.failed', 'user:1');
    $new->record('login.success', 'user:1');

    $removed = $journal->purgeBefore(new DateTimeImmutable('2026-09-01 UTC'));

    expect($removed)->toBe(2)->and($journal->events)->toHaveCount(1);
});

it('takes a person out of the events that name them, and keeps the events', function (): void {
    $journal = new InMemoryJournal;
    $recorder = recorder(new FakeRequestContext(user: 7, login: 'olivier', address: '203.0.113.77'), $journal);
    $recorder->record('login.success', 'user:7');
    $recorder->record('user.role_changed', 'user:9', [], 5, 'x');
    $other = recorder(new FakeRequestContext(user: 5, login: 'someone', address: '198.51.100.4'), $journal);
    $other->record('user.role_changed', 'user:7', ['to' => 'editor']);

    $changed = $journal->anonymizeUser(7);

    expect($changed)->toBe(2)->and($journal->events)->toHaveCount(3)
        ->and($journal->events[0])->toMatchObject(['userId' => 0, 'actor' => '', 'ip' => ''])
        ->and($journal->events[2]->subject)->toBe('user:erased')->and($journal->events[2]->actor)->toBe('someone')
        ->and($journal->aboutUser(7, 10))->toBe([]);
});
