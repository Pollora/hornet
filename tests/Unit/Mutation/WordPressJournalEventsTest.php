<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TwoFactorJournalModule;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeRequestContext;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryWindowCounter;
use Pollora\Hornet\Tests\Fixtures\MutationWordPressHooks;

beforeEach(function (): void {
    $this->site = FakeSite::install();
    $this->journal = new InMemoryJournal;
    $this->clock = new FakeClock('2026-11-26 10:00:00');
    $this->users = [];
    $this->lookedUp = [];
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('get_userdata')->alias(fn (mixed $id): mixed => is_int($id) ? ($this->users[$id] ?? false) : false);
    Functions\when('get_user_by')->alias(function (string $field, string $value): mixed {
        $this->lookedUp[] = [$field, $value];

        foreach ($this->users as $user) {
            if ($field === 'login' && $user->user_login === $value) {
                return $user;
            }
        }

        return false;
    });
});

function mutWpEvents(object $test, int $perNetwork = 5, int $perHour = 120): JournalModule
{
    return new JournalModule(
        new JournalRecorder($test->journal, new FakeRequestContext, $test->clock),
        $test->journal,
        $test->clock,
        30,
        new FloodGuard(new InMemoryWindowCounter, $perNetwork, 600, $perHour),
        static fn (): string => '203.0.113.77',
    );
}

function mutWpSecondFactor(object $test, int $perNetwork = 5, int $perHour = 120): TwoFactorJournalModule
{
    return new TwoFactorJournalModule(
        new JournalRecorder($test->journal, new FakeRequestContext, $test->clock),
        new FloodGuard(new InMemoryWindowCounter, $perNetwork, 600, $perHour, 'journal:second-factor'),
        static fn (): string => '203.0.113.77',
    );
}

/** @return list<array{string, string, array<string, mixed>}> */
function mutWpWritten(InMemoryJournal $journal): array
{
    return array_map(static fn (Event $event): array => [$event->type, $event->subject, $event->details], $journal->events);
}

function mutWpAccount(object $test, int $id, string $login, array $roles = []): WP_User
{
    $user = FakeWpUser::named($id, $login);
    $user->roles = $roles;

    return $test->users[$id] = $user;
}

it('plans the daily purge an hour from now, and only once', function (): void {
    $planned = [];
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_schedule_event')->alias(function (int $at, string $recurrence, string $hook) use (&$planned): void {
        $planned[] = [$at, $recurrence, $hook];
    });

    $before = time();
    mutWpEvents($this)->plan();
    $after = time();

    expect($planned)->toHaveCount(1)
        ->and($planned[0][0])->toBeGreaterThanOrEqual($before + 3600)->toBeLessThanOrEqual($after + 3600)
        ->and([$planned[0][1], $planned[0][2]])->toBe(['daily', 'pollora/hornet/purge_journal']);

    Functions\when('wp_next_scheduled')->justReturn(time());
    mutWpEvents($this)->plan();

    expect($planned)->toHaveCount(1);
});

it('gives each hook of an administrator or an account the right kind of event', function (): void {
    Functions\when('is_multisite')->justReturn(false);
    $hooks = MutationWordPressHooks::of(mutWpEvents($this));

    $hooks->fire('grant_super_admin', '007');
    $hooks->fire('revoke_super_admin', null);
    $hooks->fire('wp_create_application_password', '007', ['name' => 'phone'], 'the secret');
    $hooks->fire('wp_delete_application_password', '007', ['name' => 'laptop']);
    $hooks->fire('wp_create_application_password', null, ['uuid' => 'x']);
    $hooks->fire('wp_delete_application_password', [], ['uuid' => 'x']);
    $hooks->fire('wp_create_application_password', 3, null);
    $hooks->fire('wp_delete_application_password', 3, ['name' => ['not text']]);

    expect(mutWpWritten($this->journal))->toBe([
        ['user.super_admin_granted', 'user:7', []],
        ['user.super_admin_revoked', 'user:0', []],
        ['user.app_password_created', 'user:7', ['name' => 'phone']],
        ['user.app_password_deleted', 'user:7', ['name' => 'laptop']],
        ['user.app_password_created', 'user:0', ['name' => '']],
        ['user.app_password_deleted', 'user:0', ['name' => '']],
        ['user.app_password_created', 'user:3', ['name' => '']],
        ['user.app_password_deleted', 'user:3', ['name' => '']],
    ]);
});

it('writes a failed login as nobody, with why it failed', function (): void {
    mutWpAccount($this, 3, 'ana');

    mutWpEvents($this)->loginFailed('ana', new WP_Error('incorrect_password'));

    $event = $this->journal->events[0];
    expect([$event->subject, $event->userId, $event->actor, $event->details])->toBe(['user:3', 0, '', ['reason' => 'incorrect_password']]);
});

it('looks no account up when no name was typed', function (mixed $name): void {
    mutWpEvents($this)->loginFailed($name);

    expect($this->lookedUp)->toBe([])->and($this->journal->events[0]->subject)->toBe('unknown account');
})->with(['empty' => [''], 'not text' => [['a']]]);

it('says that a network went past its limit, as nobody', function (): void {
    $module = mutWpEvents($this, perNetwork: 1);
    $module->loginFailed('x');
    $module->loginFailed('x');

    $flood = $this->journal->events[1];
    expect([$flood->type, $flood->subject, $flood->userId, $flood->actor, $flood->details])->toBe(['login.flood', 'network', 0, '', ['limit' => 1, 'seconds' => 600]]);
});

it('says that the whole site went past its limit, as nobody', function (): void {
    $module = mutWpEvents($this, perHour: 1);
    $module->loginFailed('x');
    $module->loginFailed('x');

    $flood = $this->journal->events[1];
    expect([$flood->type, $flood->subject, $flood->userId, $flood->actor, $flood->details])->toBe(['login.flood', 'site', 0, '', ['limit' => 1, 'seconds' => 3600]]);
});

it('tells the first role of a new account, or none', function (): void {
    mutWpAccount($this, 3, 'ana', ['editor']);
    mutWpAccount($this, 4, 'bo');
    $module = mutWpEvents($this);

    $module->userCreated(3);
    $module->userCreated(4);
    $module->userCreated(5);
    $module->userCreated('3');

    expect(mutWpWritten($this->journal))->toBe([
        ['user.created', 'user:3', ['role' => 'editor']],
        ['user.created', 'user:4', ['role' => '']],
        ['user.created', 'user:5', ['role' => '']],
        ['user.created', 'user:0', ['role' => '']],
    ]);
});

it('reads the number of a deleted account', function (): void {
    $module = mutWpEvents($this);
    $module->networkUserDeleted('007');
    $module->networkUserDeleted(null);

    expect(array_column(mutWpWritten($this->journal), 1))->toBe(['user:7', 'user:0']);
});

it('tells an account that arrives on a site only when that account, on that site, was just given its first role', function (): void {
    $module = mutWpEvents($this);

    $module->roleSet('007', 'editor', []);
    $module->addedToSite('007', 'editor', '1');

    $module->roleSet(null, 'editor', []);
    $module->addedToSite(null, 'author', 1);

    // A site that is not told is not taken for any site
    $module->roleSet(3, 'editor', []);
    $module->addedToSite(3, 'editor', null);

    $module->roleSet(3, 'editor', []);
    $module->addedToSite(3, ['not a role'], 1);

    expect(mutWpWritten($this->journal))->toBe([
        ['user.role_changed', 'user:7', ['added' => 'editor']],
        ['user.role_changed', 'user:0', ['added' => 'author']],
    ]);
});

it('does not take a missing site number for the first site', function (): void {
    Functions\when('get_current_blog_id')->justReturn(0);
    $module = mutWpEvents($this);
    $module->roleSet(3, 'editor', []);
    $module->addedToSite(3, 'editor', null);

    expect(mutWpWritten($this->journal))->toBe([['user.role_changed', 'user:3', ['added' => 'editor']]]);
});

it('tells the roles an account had when it leaves a site, whatever the type of its number', function (): void {
    mutWpAccount($this, 3, 'ana', ['editor', 'author']);
    mutWpAccount($this, 4, 'bo');
    $module = mutWpEvents($this);

    $module->removedFromSite('3');
    $module->removedFromSite(4);
    $module->removedFromSite('x');

    expect(mutWpWritten($this->journal))->toBe([['user.role_changed', 'user:3', ['removed' => 'editor,author']]]);
});

it('tells a change of role as one change, with the roles before and after', function (): void {
    $module = mutWpEvents($this);
    $module->roleSet('007', 'editor', ['author', 5, 'subscriber']);
    $module->roleSet(null, ['x'], ['author']);

    expect(mutWpWritten($this->journal))->toBe([
        ['user.role_changed', 'user:7', ['from' => 'author,subscriber', 'to' => 'editor']],
        ['user.role_changed', 'user:0', ['from' => 'author', 'to' => '']],
    ]);
});

it('keeps the roles added and removed by themselves until the end of the request', function (): void {
    Functions\when('is_multisite')->justReturn(false);
    $module = mutWpEvents($this);
    $module->roleAdded('007', 'editor');
    $module->roleRemoved('007', 'author');
    $module->roleAdded(null, ['x']);
    $module->roleRemoved([], 5);

    expect($this->journal->events)->toBe([]);

    $module->flush();

    expect(mutWpWritten($this->journal))->toBe([
        ['user.role_changed', 'user:7', ['added' => 'editor']],
        ['user.role_changed', 'user:7', ['removed' => 'author']],
        ['user.role_changed', 'user:0', ['added' => '']],
        ['user.role_changed', 'user:0', ['removed' => '']],
    ]);

    $module->flush();

    expect($this->journal->events)->toHaveCount(4);
});

it('writes each waiting role on the site it happened on, then comes back', function (): void {
    $site = 2;
    $moves = [];
    Functions\when('get_current_blog_id')->alias(function () use (&$site): int {
        return $site;
    });
    Functions\when('switch_to_blog')->alias(function (int $to) use (&$moves): void {
        $moves[] = 'to '.$to;
    });
    Functions\when('restore_current_blog')->alias(function () use (&$moves): void {
        $moves[] = 'back';
    });
    $module = mutWpEvents($this);
    $module->roleAdded(3, 'editor');
    $site = 1;
    $module->roleAdded(4, 'editor');
    $module->flush();

    expect($moves)->toBe(['to 2', 'back'])->and($this->journal->events)->toHaveCount(2);
});

it('names a theme that was switched to or deleted, and nothing when it is not told', function (): void {
    $module = mutWpEvents($this);
    $module->themeSwitched('Twenty');
    $module->themeSwitched(null);
    $module->themeDeleted(null);
    $module->themeDeleted('twentyten');
    $module->themeDeleted('kept', false);

    expect(mutWpWritten($this->journal))->toBe([
        ['theme.switched', 'theme:Twenty', ['from' => '']],
        ['theme.switched', 'theme:', ['from' => '']],
        ['theme.deleted', 'theme:', []],
        ['theme.deleted', 'theme:twentyten', []],
    ]);
});

it('names nothing for a plugin that is not told', function (mixed $file): void {
    mutWpEvents($this)->pluginDeleted($file);

    expect($this->journal->events[0]->subject)->toBe('plugin:');
})->with(['empty' => [''], 'not text' => [null]]);

it('says the new value only of the options whose value is nobody\'s secret', function (): void {
    $module = mutWpEvents($this);
    $module->optionChanged('users_can_register', 0, true);
    $module->optionChanged('default_role', 'author', 'editor');
    $module->optionChanged('admin_email', 'a@example.com', 'b@example.com');
    $module->optionChanged('home', 'x', ['y']);
    $module->optionChanged('blogname', 'x', 'y');
    $module->optionChanged('home', 'same', 'same');

    expect(mutWpWritten($this->journal))->toBe([
        ['option.changed', 'option:users_can_register', ['to' => 1]],
        ['option.changed', 'option:default_role', ['to' => 'editor']],
        ['option.changed', 'option:admin_email', ['changed' => true]],
        ['option.changed', 'option:home', ['changed' => true]],
    ]);
});

it('counts what the last scan found failing and could not verify', function (): void {
    (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable('2026-11-26'), [
        'a' => Finding::fail('a', Severity::High, 'x'),
        'b' => Finding::fail('b', Severity::High, 'x'),
        'c' => Finding::unknown('c', 'x'),
        'd' => Finding::pass('d', 'x'),
        'e' => Finding::pass('e', 'x'),
        'f' => Finding::pass('f', 'x'),
    ]));

    mutWpEvents($this)->scanCompleted();

    expect(mutWpWritten($this->journal))->toBe([['hornet.scan_completed', 'scan', ['failed' => 2, 'unknown' => 1]]]);
});

it('tells a second factor passed by that account, and ignores what is not an account', function (): void {
    $module = mutWpSecondFactor($this);
    $module->passed(null, null, false);
    $module->passed(mutWpAccount($this, 12, 'marie'), new stdClass, false);

    expect(mutWpWritten($this->journal))->toBe([['login.second_factor_passed', 'user:12', ['method' => 'other']]])
        ->and($this->journal->events[0]->userId)->toBe(12);
});

it('tells a login and a revalidation apart through the hooks of the plugin', function (): void {
    $hooks = MutationWordPressHooks::of(mutWpSecondFactor($this));
    $user = mutWpAccount($this, 12, 'marie');
    $provider = new class
    {
        public function get_key(): string
        {
            return 'Two_Factor_Dummy';
        }
    };

    $hooks->fire('two_factor_user_authenticated', $user, $provider);
    $hooks->fire('two_factor_user_revalidated', $user, $provider);

    expect(array_column(mutWpWritten($this->journal), 2))->toBe([['method' => 'dummy'], ['method' => 'dummy', 'revalidation' => true]]);
});

it('tells which account a refused code aimed at, as nobody', function (): void {
    mutWpAccount($this, 12, 'marie');

    mutWpSecondFactor($this)->failed('marie', new WP_Error('two_factor_provider_missing'));

    $event = $this->journal->events[0];
    expect([$event->subject, $event->userId, $event->actor])->toBe(['user:12', 0, '']);
});

it('looks no account up for a refused code without a name', function (mixed $name): void {
    $module = mutWpSecondFactor($this);
    $module->failed($name, new WP_Error('two_factor_invalid'));
    $module->failed($name);

    expect($this->lookedUp)->toBe([])->and(array_column(mutWpWritten($this->journal), 1))->toBe(['unknown account']);
})->with(['empty' => [''], 'not text' => [null]]);

it('says that refused codes went past a limit, as nobody', function (int $perNetwork, int $perHour, string $subject, int $limit, int $seconds): void {
    $module = mutWpSecondFactor($this, $perNetwork, $perHour);
    $module->failed('x', new WP_Error('two_factor_invalid'));
    $module->failed('x', new WP_Error('two_factor_invalid'));

    $flood = $this->journal->events[1];
    expect([$flood->subject, $flood->userId, $flood->actor, $flood->details])->toBe([$subject, 0, '', ['of' => 'second_factor', 'limit' => $limit, 'seconds' => $seconds]]);
})->with(['network' => [1, 120, 'network', 1, 600], 'site' => [5, 1, 'site', 1, 3600]]);

it('reads the methods of a user as one list of names, and tells only what changed', function (): void {
    $stored = [4 => 'a note', 9 => 'Two_Factor_Totp', 11 => 'Two_Factor_Totp'];
    $asked = [];
    Functions\when('get_user_meta')->alias(function (mixed ...$arguments) use (&$stored, &$asked): array {
        $asked[] = $arguments;

        return $stored;
    });
    $module = mutWpSecondFactor($this);

    $module->remember('12', '_two_factor_enabled_providers');
    $stored = [7 => 5, 8 => 'a note', 9 => 'Two_Factor_Email', 10 => 'Two_Factor_Email'];
    $module->changed(1, '12', '_two_factor_enabled_providers');

    expect(mutWpWritten($this->journal))->toBe([
        ['user.second_factor_enabled', 'user:12', ['method' => 'email']],
        ['user.second_factor_disabled', 'user:12', ['method' => 'totp']],
    ]);

    // What was remembered is used once
    $module->remember(12, '_two_factor_enabled_providers');
    $module->changed(1, 12, '_two_factor_enabled_providers');

    // The one value of the key, not the list of its rows
    expect($this->journal->events)->toHaveCount(2)->and($asked[0])->toBe([12, '_two_factor_enabled_providers', true]);
});

it('follows the first account of a site, and no account without a number', function (): void {
    $stored = ['Two_Factor_Totp'];
    Functions\when('get_user_meta')->alias(function () use (&$stored): array {
        return $stored;
    });
    $module = mutWpSecondFactor($this);

    $module->remember(1, '_two_factor_enabled_providers');
    $stored = [];
    $module->changed(1, 1, '_two_factor_enabled_providers');
    $stored = ['Two_Factor_Totp'];
    $module->changed(1, 0, '_two_factor_enabled_providers');
    $module->changed(1, -3, '_two_factor_enabled_providers');
    $module->changed(1, 'x', '_two_factor_enabled_providers');

    expect(array_column(mutWpWritten($this->journal), 1))->toBe(['user:1']);
});

it('hears of the metadata through every hook of WordPress, and of an account being deleted', function (string $before, string $after): void {
    $stored = ['Two_Factor_Totp'];
    Functions\when('get_user_meta')->alias(function () use (&$stored): array {
        return $stored;
    });
    $hooks = MutationWordPressHooks::of(mutWpSecondFactor($this));
    $arguments = $before === 'add_user_meta' ? [12, '_two_factor_enabled_providers'] : [1, 12, '_two_factor_enabled_providers'];

    $hooks->fire($before, ...$arguments);
    $stored = [];
    $hooks->fire($after, 1, 12, '_two_factor_enabled_providers');

    expect(array_column(mutWpWritten($this->journal), 0))->toBe(['user.second_factor_disabled']);

    $stored = ['Two_Factor_Totp'];
    $hooks->fire('delete_user', '12');
    $hooks->fire($after, 1, 12, '_two_factor_enabled_providers');

    expect($this->journal->events)->toHaveCount(1);
})->with([
    ['add_user_meta', 'added_user_meta'],
    ['update_user_meta', 'updated_user_meta'],
    ['delete_user_meta', 'deleted_user_meta'],
]);

it('reads nothing before a write that is not about the methods of an account', function (): void {
    $asked = 0;
    Functions\when('get_user_meta')->alias(function () use (&$asked): array {
        $asked++;

        return [];
    });
    $module = mutWpSecondFactor($this);

    $module->remember(12, 'nickname');
    $module->remember(0, '_two_factor_enabled_providers');
    $module->remember('x', '_two_factor_enabled_providers');

    expect($asked)->toBe(0);

    $module->remember(1, '_two_factor_enabled_providers');

    expect($asked)->toBe(1);
});
