<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStore;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

function scanTask(bool $unfinished): ScanTask
{
    return new readonly class($unfinished) implements ScanTask
    {
        public function __construct(private bool $unfinished) {}

        public function id(): string
        {
            return CoreIntegrityTask::ID;
        }

        public function run(?Pins $pinned, bool $repin): TaskResult
        {
            return new TaskResult(Finding::pass(CoreIntegrityTask::ID, 'fine'), null, $this->unfinished);
        }
    };
}

/**
 * @param  list<string>  $left  the tasks that stopped on their budget at the last run
 */
function scanModule(bool $unfinished, ArrayLock $lock, string $schedule = 'daily', int &$built = 0, array $left = []): ScanModule
{
    return new ScanModule(function () use ($unfinished, &$built): Scanner {
        $built++;

        return new Scanner([scanTask($unfinished)], new InMemoryScanStore, new FakeClock);
    }, $schedule, $lock, static fn (): array => $left);
}

it('attaches the init hook, the scan and its continuation', function (): void {
    $hooks = new RecordingHooks;
    scanModule(false, new ArrayLock)->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->toBe(['init', 'pollora/hornet/scheduled_scan', 'pollora/hornet/continue_scan']);
});

it('schedules the scan once, ten minutes from now', function (): void {
    Functions\when('wp_get_schedule')->justReturn(false);
    Functions\expect('wp_clear_scheduled_hook')->once()->with('pollora/hornet/scheduled_scan');
    Functions\expect('wp_schedule_event')->once()->withArgs(fn (int $when, string $recurrence, string $hook): bool => $when > time() + 500 && $when <= time() + 601 && $recurrence === 'hourly' && $hook === 'pollora/hornet/scheduled_scan');

    scanModule(false, new ArrayLock, 'hourly')->plan();
});

it('leaves a schedule alone when it is the one asked for, and moves it when it is not', function (): void {
    Functions\when('wp_get_schedule')->justReturn('daily');
    Functions\expect('wp_schedule_event')->never();
    Functions\expect('wp_clear_scheduled_hook')->never();

    scanModule(false, new ArrayLock)->plan();
});

it('takes the scan out of the cron when it is turned off', function (): void {
    Functions\when('wp_get_schedule')->justReturn('daily');
    Functions\expect('wp_clear_scheduled_hook')->once()->with('pollora/hornet/scheduled_scan');
    Functions\expect('wp_schedule_event')->never();

    scanModule(false, new ArrayLock, 'off')->plan();
});

it('does nothing when it is off and nothing was scheduled', function (): void {
    Functions\when('wp_get_schedule')->justReturn(false);
    Functions\expect('wp_clear_scheduled_hook')->never();

    scanModule(false, new ArrayLock, 'off')->plan();
});

it('runs the scan under the lock, and lets go of it', function (): void {
    Functions\expect('wp_schedule_single_event')->never();
    $lock = new ArrayLock;
    $built = 0;

    scanModule(false, $lock, 'daily', $built)->scan();

    expect($built)->toBe(1)->and($lock->held)->toBeFalse()->and($lock->released)->toBe(1);
});

it('goes on in a minute when a task did not finish, once', function (): void {
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\expect('wp_schedule_single_event')->once()->withArgs(fn (int $when, string $hook): bool => $hook === 'pollora/hornet/continue_scan' && $when > time() + 50 && $when <= time() + 61);

    scanModule(true, new ArrayLock)->scan();
});

it('does not plan a second continuation', function (): void {
    Functions\when('wp_next_scheduled')->justReturn(1234567890);
    Functions\expect('wp_schedule_single_event')->never();

    scanModule(true, new ArrayLock)->scan();
});

it('steps aside when another run holds the lock', function (): void {
    $lock = new ArrayLock;
    $lock->held = true;
    $built = 0;

    scanModule(false, $lock, 'daily', $built)->scan();

    expect($built)->toBe(0)->and($lock->held)->toBeTrue();
});

it('lets go of the lock when the scan fails', function (): void {
    $lock = new ArrayLock;
    $module = new ScanModule(static fn (): never => throw new RuntimeException('boom'), 'daily', $lock, static fn (): array => []);

    expect(fn () => $module->scan())->toThrow(RuntimeException::class)->and($lock->held)->toBeFalse();
});

it('does not build a scanner that is not there (Hornet off, folders unreadable)', function (): void {
    $lock = new ArrayLock;

    (new ScanModule(static fn (): ?Scanner => null, 'daily', $lock, static fn (): array => []))->scan();

    expect($lock->held)->toBeFalse()->and($lock->released)->toBe(1);
});

it('goes on with the tasks that were left, and with nothing else', function (): void {
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\expect('wp_schedule_single_event')->never();
    $lock = new ArrayLock;
    $built = 0;

    scanModule(false, $lock, 'daily', $built, [CoreIntegrityTask::ID])->continue();
    scanModule(false, $lock, 'daily', $built)->continue();

    // The second one had nothing left to do: it did not even build a scanner
    expect($built)->toBe(1);
});
