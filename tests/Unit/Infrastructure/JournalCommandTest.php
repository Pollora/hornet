<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Domain\Journal\Journal;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Infrastructure\WpCli\JournalCommand;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeRequestContext;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\RecordingOutput;

function journalCommand(InMemoryJournal $journal, RecordingOutput $output, ?Closure &$dropped = null, int $days = 30): JournalCommand
{
    $dropped = null;
    $calls = 0;

    return new JournalCommand(static fn (): Journal => $journal, function () use (&$dropped, &$calls): void {
        $calls++;
        $dropped = $calls;
    }, $days, new FakeClock('2026-11-25 10:00:00'), $output);
}

function aJournalWithThreeEvents(): InMemoryJournal
{
    $journal = new InMemoryJournal;
    $at = static fn (string $when, Source $source, int $user, string $login): JournalRecorder => new JournalRecorder($journal, new FakeRequestContext($source, $user, $login, '203.0.113.9'), new FakeClock($when));
    $at('2026-11-01 10:00:00', Source::Web, 0, '')->record('login.failed', 'unknown account', ['reason' => 'invalid_username']);
    $at('2026-11-20 10:00:00', Source::Admin, 1, 'admin')->record('user.role_changed', 'user:5', ['from' => 'editor', 'to' => 'administrator']);
    $at('2026-11-24 10:00:00', Source::Cli, 0, '')->record('plugin.activated', 'plugin:akismet');

    return $journal;
}

it('lists the events, the newest first, with who, from where and about what', function (): void {
    $output = new RecordingOutput;

    journalCommand(aJournalWithThreeEvents(), $output)->list();

    expect(array_column($output->rows, 'type'))->toBe(['plugin.activated', 'user.role_changed', 'login.failed'])
        ->and($output->rows[1])->toMatchArray(['when' => '2026-11-20 10:00:00', 'source' => 'admin', 'by' => 'admin', 'about' => 'user:5', 'network' => '203.0.113.0'])
        ->and($output->rows[1]['details'])->toBe('{"from":"editor","to":"administrator"}')
        ->and($output->rows[0]['by'])->toBe('-');
});

it('narrows by type, group, source, account and days', function (): void {
    $run = static function (array $assoc): array {
        $output = new RecordingOutput;
        journalCommand(aJournalWithThreeEvents(), $output)->list([], $assoc);

        return array_column($output->rows, 'type');
    };

    expect($run(['type' => 'login.failed']))->toBe(['login.failed'])
        ->and($run(['type' => 'user.']))->toBe(['user.role_changed'])
        ->and($run(['source' => 'cli']))->toBe(['plugin.activated'])
        ->and($run(['user' => '1']))->toBe(['user.role_changed'])
        ->and($run(['days' => '3']))->toBe(['plugin.activated'])
        ->and($run(['limit' => '1']))->toBe(['plugin.activated']);
});

it('says when nothing matches', function (): void {
    $output = new RecordingOutput;

    journalCommand(aJournalWithThreeEvents(), $output)->list([], ['type' => 'core.updated']);

    expect($output->warnings)->toBe(['The journal holds no such event.']);
});

it('refuses what it does not know', function (array $assoc, string $message): void {
    expect(fn () => journalCommand(aJournalWithThreeEvents(), new RecordingOutput)->list([], $assoc))->toThrow(RuntimeException::class, $message);
})->with([
    'a format' => [['format' => 'xml'], 'Unknown format'],
    'a source' => [['source' => 'mars'], 'Unknown source'],
    'a type' => [['type' => 'login.exploded'], 'Unknown type'],
    'a group' => [['type' => 'nothing.'], 'Unknown type'],
]);

it('forgets what is older than the retention', function (): void {
    $journal = aJournalWithThreeEvents();
    $output = new RecordingOutput;

    journalCommand($journal, $output, days: 10)->purge();

    expect($output->successes)->toBe(['1 events older than 10 days were forgotten.'])->and($journal->events)->toHaveCount(2);
});

it('removes the journal only when told to mean it', function (): void {
    $output = new RecordingOutput;
    $command = journalCommand(aJournalWithThreeEvents(), $output, $dropped);

    expect(fn () => $command->drop())->toThrow(RuntimeException::class, '--yes')->and($dropped)->toBeNull();

    $command->drop([], ['yes' => true]);

    expect($dropped)->toBe(1)->and($output->successes)->toBe(['The journal was removed.']);
});

it('declares its flags the way WP-CLI reads them: a flag it does not declare is refused, however the method is written', function (): void {
    $doc = (string) (new ReflectionMethod(JournalCommand::class, 'drop'))->getDocComment();

    // `--yes` alone would be a required parameter with a value; WP-CLI answers "unknown --yes parameter" to `--yes` on the command line
    expect($doc)->toContain('[--yes]')->and(preg_match('/^\s*\*\s*--yes\s*$/m', $doc))->toBe(0);

    foreach (['list' => ['[--type=<type>]', '[--source=<source>]', '[--user=<id>]', '[--days=<days>]', '[--limit=<n>]', '[--format=<format>]']] as $method => $flags) {
        $doc = (string) (new ReflectionMethod(JournalCommand::class, $method))->getDocComment();

        foreach ($flags as $flag) {
            expect($doc)->toContain($flag);
        }
    }
});
