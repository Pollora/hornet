<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Login\Unlocker;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Infrastructure\WpCli\HornetCommand;
use Pollora\Hornet\Infrastructure\WpCli\JournalCommand;
use Pollora\Hornet\Infrastructure\WpCli\NotifyCommand;
use Pollora\Hornet\Infrastructure\WpCli\ScanCommand;
use Pollora\Hornet\Infrastructure\WpCli\ServerConfigCommand;
use Pollora\Hornet\Infrastructure\WpCli\UnlockCommand;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryAttemptStore;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\QuietOutput;

/*
 * A command that refuses says why, once, and does nothing of what it was asked: it does not count on `error()` to stop it.
 */

it('sends nothing without a channel, or with settings that cannot be read', function (string $method, array $arguments, Closure $service, string $why): void {
    $output = new QuietOutput;

    (new NotifyCommand($service, $output))->{$method}($arguments);

    expect($output->said)->toHaveCount(1)->and($output->said[0][0])->toBe('error')->and($output->said[0][1])->toContain($why);
})->with([
    ['run', []],
    ['test', ['team']],
    ['status', []],
    ['retry', []],
])->with([
    'no channel' => [static fn (): null => null, 'No notification channel is configured'],
    'wrong settings' => [static fn (): never => throw InvalidSettingsException::unknownKey('chanels', 'notifications'), 'chanels'],
]);

it('asks for the name of a channel before it builds anything', function (): void {
    $output = new QuietOutput;

    (new NotifyCommand(static fn (): never => throw new LogicException('Built.'), $output))->test([]);

    expect($output->said)->toBe([['error', 'Usage: wp hornet notify test <channel>']]);
});

it('runs no audit and no scan on an argument it does not know', function (string $command, array $assoc): void {
    $output = new QuietOutput;
    $never = static fn (): never => throw new LogicException('It ran.');
    $subject = $command === 'status' ? [new HornetCommand($never, $output), 'status'] : new ScanCommand($never, $output);

    $subject([], $assoc);

    expect($output->said)->toHaveCount(1)->and($output->said[0][0])->toBe('error');
})->with(['status', 'scan'])->with([
    'a format' => [['format' => 'xml']],
    'a severity' => [['fail-on' => 'enormous']],
]);

it('says why the audit cannot run on wrong settings, and prints no result', function (): void {
    $output = new QuietOutput;

    (new HornetCommand(static fn (): Report => throw InvalidSettingsException::unknownKey('enable', ''), $output))->status();

    expect($output->said)->toHaveCount(1)->and($output->said[0][1])->toContain('"enable"');
});

it('scans nothing when there is no scanner', function (): void {
    $output = new QuietOutput;

    (new ScanCommand(static fn (): null => null, $output))();

    expect($output->said)->toHaveCount(1)->and($output->said[0][1])->toContain('nothing was scanned');
});

it('keeps the journal on a drop that was not confirmed, and reads nothing on a filter it does not know', function (string $method, array $assoc): void {
    $output = new QuietOutput;
    $never = static fn (): never => throw new LogicException('It went on.');
    $command = new JournalCommand($never, $never, $never, new FakeClock, $output);

    $command->{$method}([], $assoc);

    expect($output->said)->toHaveCount(1)->and($output->said[0][0])->toBe('error');
})->with([
    ['drop', []],
    ['list', ['format' => 'xml']],
    ['list', ['source' => 'mars']],
    ['list', ['type' => 'nothing.']],
]);

it('says that the journal holds nothing rather than print an empty table', function (): void {
    $output = new QuietOutput;

    (new JournalCommand(static fn (): InMemoryJournal => new InMemoryJournal, static fn () => null, static fn (): int => 30, new FakeClock, $output))->list();

    expect($output->said)->toBe([['warning', 'The journal holds no such event.']]);
});

it('does not say that an address is let back in when it was given none, or something else', function (array $arguments, string $why): void {
    $output = new QuietOutput;

    (new UnlockCommand(static fn (): Unlocker => new Unlocker(new InMemoryAttemptStore, 'salt'), $output))($arguments);

    expect($output->said)->toHaveCount(1)->and($output->said[0][0])->toBe('error')->and($output->said[0][1])->toContain($why);
})->with([
    [[], 'Usage'],
    [['everyone'], 'not an IP address'],
]);

it('prints no rule for a server it does not know', function (): void {
    $output = new QuietOutput;

    (new ServerConfigCommand(static fn (): string => '/wp-content/uploads', $output))(['iis']);

    expect($output->said)->toHaveCount(1)->and($output->said[0][0])->toBe('error');
});
