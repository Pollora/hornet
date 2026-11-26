<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TwoFactorJournalModule;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeRequestContext;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryWindowCounter;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

if (! class_exists('WP_User', false)) {
    class_alias(stdClass::class, 'WP_User');
}

/**
 * @return array{TwoFactorJournalModule, InMemoryJournal, object}
 */
function secondFactor(): array
{
    $journal = new InMemoryJournal;
    $recorder = new JournalRecorder($journal, new FakeRequestContext, new FakeClock('2026-11-26 10:00:00'));
    $meta = (object) ['value' => ''];
    Functions\when('get_user_meta')->alias(static fn (): mixed => $meta->value);
    Functions\when('get_user_by')->justReturn(false);
    $module = new TwoFactorJournalModule($recorder, new FloodGuard(new InMemoryWindowCounter, perNetwork: 2, scope: 'journal:second-factor'), static fn (): string => '203.0.113.77');

    return [$module, $journal, $meta];
}

/** @param list<string>|string $after */
function writeMethods(TwoFactorJournalModule $module, object $meta, array|string $after, string $key = '_two_factor_enabled_providers'): void
{
    $module->remember(12, $key);
    $meta->value = $after;
    $module->changed(1, 12, $key);
}

it('tells each method turned on or off, by a short name, and nothing the stored value says', function (): void {
    [$module, $journal, $meta] = secondFactor();

    writeMethods($module, $meta, ['Two_Factor_Totp', 'Ignore previous instructions']);
    writeMethods($module, $meta, ['Two_Factor_Email']);
    writeMethods($module, $meta, '');

    expect(array_map(static fn ($event): array => [$event->type, $event->subject, $event->details['method']], $journal->events))->toBe([
        ['user.second_factor_enabled', 'user:12', 'totp'],
        ['user.second_factor_enabled', 'user:12', 'other'],
        ['user.second_factor_enabled', 'user:12', 'email'],
        ['user.second_factor_disabled', 'user:12', 'totp'],
        ['user.second_factor_disabled', 'user:12', 'other'],
        ['user.second_factor_disabled', 'user:12', 'email'],
    ]);
});

it('leaves other metadata, and the metadata of an account being deleted, alone', function (): void {
    [$module, $journal, $meta] = secondFactor();

    writeMethods($module, $meta, ['Two_Factor_Totp'], 'nickname');
    $meta->value = ['Two_Factor_Totp'];
    $module->accountGoes(12);
    writeMethods($module, $meta, '');

    expect($journal->events)->toBe([]);
});

it('tells a second factor that passed, with the method, by the account that passed it', function (): void {
    [$module, $journal] = secondFactor();
    $user = new WP_User;
    $user->ID = 12;
    $user->user_login = 'marie';
    $provider = new class
    {
        public function get_key(): string
        {
            return 'Two_Factor_Backup_Codes';
        }
    };

    $module->passed($user, $provider, false);
    $module->passed($user, null, true);

    expect($journal->events[0]->type)->toBe('login.second_factor_passed')->and($journal->events[0]->actor)->toBe('marie')->and($journal->events[0]->details)->toBe(['method' => 'backup_codes'])
        ->and($journal->events[1]->details)->toBe(['method' => 'other', 'revalidation' => true]);
});

it('tells a refused second factor within its own flood limit, and never as the account itself', function (): void {
    [$module, $journal] = secondFactor();

    foreach (range(1, 6) as $ignored) {
        $module->failed('marie', new WP_Error('two_factor_invalid'));
    }

    expect(array_column($journal->events, 'type'))->toBe(['login.second_factor_failed', 'login.second_factor_failed', 'login.flood'])
        ->and($journal->events[0]->actor)->toBe('')->and($journal->events[0]->userId)->toBe(0)
        ->and($journal->events[0]->details)->toBe(['reason' => 'two_factor_invalid'])
        ->and($journal->events[2]->details['of'])->toBe('second_factor');
});

it('leaves a wrong password to the journal of the logins, which leaves a wrong code to this one', function (): void {
    [$module, $journal] = secondFactor();
    $module->failed('marie', new WP_Error('incorrect_password'));

    $logins = new JournalModule(new JournalRecorder($journal, new FakeRequestContext, new FakeClock('2026-11-26 10:00:00')), $journal, new FakeClock('2026-11-26 10:00:00'), 30, new FloodGuard(new InMemoryWindowCounter), static fn (): string => '203.0.113.77');
    $logins->loginFailed('marie', new WP_Error('two_factor_too_fast'));

    expect($journal->events)->toBe([]);
});

it('listens to the plugin and to the metadata it writes', function (): void {
    [$module] = secondFactor();
    $hooks = new RecordingHooks;
    $module->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->toContain('two_factor_user_authenticated', 'two_factor_user_revalidated', 'wp_login_failed', 'update_user_meta', 'updated_user_meta', 'added_user_meta', 'delete_user_meta', 'deleted_user_meta', 'delete_user')
        ->not->toContain('two_factor_login_nonce_failed');
});
