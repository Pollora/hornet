<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeRequestContext;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeTheme;
use Pollora\Hornet\Tests\Fixtures\FakeWpError;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryWindowCounter;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

beforeEach(function (): void {
    Functions\when('is_multisite')->justReturn(false);
    Functions\when('get_current_blog_id')->justReturn(1);
    $this->site = FakeSite::install();
    $this->journal = new InMemoryJournal;
    $this->clock = new FakeClock('2026-11-26 10:00:00');
    $ana = FakeWpUser::named(3, 'ana');
    $ana->roles = ['editor'];
    Functions\when('get_user_by')->alias(static fn (string $field, string $value): mixed => ($field === 'login' && $value === 'ana') || ($field === 'email' && $value === 'ana@example.com') ? $ana : false);
    Functions\when('get_userdata')->alias(static fn (int $id): mixed => $id === 3 ? $ana : false);
    $this->ana = $ana;
});

function journalModule(object $test, ?Closure $pending = null, int $perNetwork = 5, int $perHour = 120): JournalModule
{
    return new JournalModule(
        new JournalRecorder($test->journal, new FakeRequestContext, $test->clock),
        $test->journal,
        $test->clock,
        30,
        new FloodGuard(new InMemoryWindowCounter, $perNetwork, 600, $perHour),
        static fn (): string => '203.0.113.77',
        $pending,
    );
}

/**
 * @return list<array{string, string, array<string, mixed>}> what was written, in short
 */
function written(InMemoryJournal $journal): array
{
    return array_map(static fn (Event $event): array => [$event->type, $event->subject, $event->details], $journal->events);
}

it('makes the table before anything can happen, and purges once a day', function (): void {
    $hooks = new RecordingHooks;
    journalModule($this)->register($hooks);

    expect($hooks->registered[0]['hook'])->toBe('init')->and($hooks->registered[0]['priority'])->toBe(0)
        ->and(array_column($hooks->registered, 'hook'))->toContain('wpmu_delete_user', 'remove_user_from_blog', 'add_user_to_blog', JournalModule::PURGE_HOOK, 'wp_login', 'wp_login_failed', 'after_password_reset', 'wp_pre_insert_user_data', 'user_register', 'delete_user', 'set_user_role', 'shutdown', 'add_user_role', 'remove_user_role', 'grant_super_admin', 'revoke_super_admin', 'wp_create_application_password', 'wp_delete_application_password', 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme', 'deleted_theme', 'upgrader_process_complete', 'updated_option', 'pollora/hornet/scan_completed');
});

it('writes the changes of who runs the site and of its plugins, through the hooks it attaches', function (): void {
    $hooks = new RecordingHooks;
    journalModule($this)->register($hooks);

    $hooks->callback('grant_super_admin')(3);
    $hooks->callback('revoke_super_admin')('3');
    $hooks->callback('activated_plugin')('akismet/akismet.php');
    $hooks->callback('deactivated_plugin')('hello.php');

    expect(written($this->journal))->toBe([
        ['user.super_admin_granted', 'user:3', []],
        ['user.super_admin_revoked', 'user:3', []],
        ['plugin.activated', 'plugin:akismet', []],
        ['plugin.deactivated', 'plugin:hello', []],
    ]);
});

it('plans the purge only when none is planned', function (mixed $next, int $times): void {
    Functions\when('wp_next_scheduled')->justReturn($next);
    Functions\expect('wp_schedule_event')->times($times)->withArgs(fn (int $at, string $recurrence, string $hook): bool => $at > time() + 3500 && $recurrence === 'daily' && $hook === 'pollora/hornet/purge_journal');

    journalModule($this)->plan();
})->with([[false, 1], [1893456000, 0]]);

it('forgets what is older than the retention, and nothing else', function (): void {
    $module = journalModule($this);
    $at = static fn (string $time): Event => new Event(new DateTimeImmutable($time.' UTC'), 'login.success', Source::Web);
    $this->journal->events = [$at('2026-10-27 09:59:59'), $at('2026-10-27 10:00:00'), $at('2026-11-26 09:00:00')];

    expect($module->purge())->toBe(1)->and($this->journal->events)->toHaveCount(2);
});

it('writes a login under the account that logged in, and says when a second factor is still to pass', function (?bool $pending, array $details): void {
    $module = journalModule($this, $pending === null ? null : static fn (WP_User $user): bool => $pending);
    $module->loggedIn('ana', $this->ana);
    $module->loggedIn('ana', null);

    expect(written($this->journal))->toBe([['login.success', 'user:3', $details]])
        ->and($this->journal->events[0]->userId)->toBe(3)
        ->and($this->journal->events[0]->actor)->toBe('ana');
})->with([
    'no second factor plugin' => [null, []],
    'passed' => [false, []],
    'pending' => [true, ['second_factor' => 'pending']],
]);

it('writes a failed login against the account it aimed at, with nobody as its author', function (string $typed): void {
    journalModule($this)->loginFailed($typed, new FakeWpError('incorrect_password'));

    expect(written($this->journal))->toBe([['login.failed', 'user:3', ['reason' => 'incorrect_password']]])
        ->and($this->journal->events[0]->userId)->toBe(0)
        ->and($this->journal->events[0]->actor)->toBe('');
})->with(['ana', 'ana@example.com']);

it('never writes the name that was typed when it matches nobody: it may be a password', function (mixed $typed): void {
    journalModule($this)->loginFailed($typed);

    expect(written($this->journal))->toBe([['login.failed', 'unknown account', ['reason' => '']]])
        ->and(json_encode($this->journal->events[0]->toArray()))->not->toContain('hunter2');
})->with(['hunter2', '', null]);

it('leaves a refused second factor to the module that tells those', function (): void {
    journalModule($this)->loginFailed('ana', new FakeWpError('two_factor_invalid'));

    expect($this->journal->events)->toBe([]);
});

it('keeps a flood of failures from one network within bounds, saying once that it is one', function (): void {
    $module = journalModule($this, perNetwork: 2);

    foreach (range(1, 6) as $ignored) {
        $module->loginFailed('nobody');
    }

    expect(array_column(written($this->journal), 0))->toBe(['login.failed', 'login.failed', 'login.flood'])
        ->and(written($this->journal)[2])->toBe(['login.flood', 'network', ['limit' => 2, 'seconds' => 600]]);
});

it('keeps a flood from many networks within the hourly bound of the site', function (): void {
    $module = journalModule($this, perHour: 1);

    foreach (range(1, 4) as $ignored) {
        $module->loginFailed('nobody');
    }

    expect(written($this->journal))->toBe([['login.failed', 'unknown account', ['reason' => '']], ['login.flood', 'site', ['limit' => 1, 'seconds' => 3600]]]);
});

it('writes a password reset under the account', function (): void {
    $module = journalModule($this);
    $module->passwordReset($this->ana);
    $module->passwordReset('ana');

    expect(written($this->journal))->toBe([['login.password_reset', 'user:3', []]])->and($this->journal->events[0]->userId)->toBe(3);
});

it('writes a new account with the role it was given, and not that role as a change of role', function (): void {
    $module = journalModule($this);
    $data = ['user_login' => 'ana'];

    expect($module->creating($data, false))->toBe($data);

    $module->roleAdded(3, 'editor');
    $module->userCreated(3);
    $module->flush();

    expect(written($this->journal))->toBe([['user.created', 'user:3', ['role' => 'editor']]]);

    // Once it exists, a role that is added is a change
    $module->roleAdded(3, 'author');
    $module->flush();

    expect(written($this->journal)[1])->toBe(['user.role_changed', 'user:3', ['added' => 'author']]);
});

it('does not hold back the roles of an update', function (): void {
    $module = journalModule($this);
    $module->creating([], true);
    $module->roleAdded(3, 'author');
    $module->flush();

    expect(written($this->journal))->toBe([['user.role_changed', 'user:3', ['added' => 'author']]]);
});

it('writes an account that cannot be read without a role', function (mixed $id, string $subject): void {
    journalModule($this)->userCreated($id);

    expect(written($this->journal))->toBe([['user.created', $subject, ['role' => '']]]);
})->with([[99, 'user:99'], ['x', 'user:0']]);

it('writes a deleted account', function (): void {
    $module = journalModule($this);
    $module->userDeleted('3');
    $module->userDeleted(null);

    expect(array_column(written($this->journal), 1))->toBe(['user:3', 'user:0']);
});

it('tells a change of role once: what was added and removed to make it is not told again', function (): void {
    $module = journalModule($this);
    $module->roleRemoved(3, 'editor');
    $module->roleAdded(3, 'administrator');
    $module->roleAdded(4, 'author');
    $module->roleSet(3, 'administrator', ['editor', 12]);
    $module->flush();
    $module->flush();

    expect(written($this->journal))->toBe([
        ['user.role_changed', 'user:3', ['from' => 'editor', 'to' => 'administrator']],
        ['user.role_changed', 'user:4', ['added' => 'author']],
    ]);
});

it('says nothing of the first role of an account', function (mixed $old): void {
    journalModule($this)->roleSet(3, 'editor', $old);

    expect($this->journal->events)->toBe([]);
})->with([[[]], [null]]);

it('writes a role that is removed on its own', function (): void {
    $module = journalModule($this);
    $module->roleRemoved(3, 'editor');
    $module->flush();

    expect(written($this->journal))->toBe([['user.role_changed', 'user:3', ['removed' => 'editor']]]);
});

it('keeps the name of an application password and nothing else of it', function (): void {
    $module = journalModule($this);
    $module->appPasswordCreated(3, ['name' => 'Mobile', 'password' => 'hashed', 'uuid' => 'u']);
    $module->appPasswordDeleted(3, ['name' => 'Mobile']);
    $module->appPasswordCreated(3, 'x');
    $module->appPasswordDeleted(3, null);

    expect(written($this->journal))->toBe([
        ['user.app_password_created', 'user:3', ['name' => 'Mobile']],
        ['user.app_password_deleted', 'user:3', ['name' => 'Mobile']],
        ['user.app_password_created', 'user:3', ['name' => '']],
        ['user.app_password_deleted', 'user:3', ['name' => '']],
    ]);
});

it('writes a plugin or a theme as deleted only when its files are gone', function (): void {
    $module = journalModule($this);
    $module->pluginDeleted('akismet/akismet.php', true);
    $module->pluginDeleted('kept/kept.php', false);
    $module->themeDeleted('storefront', true);
    $module->themeDeleted('kept', false);

    expect(written($this->journal))->toBe([['plugin.deleted', 'plugin:akismet', []], ['theme.deleted', 'theme:storefront', []]]);
});

it('names a plugin that has no file by nothing', function (mixed $file): void {
    journalModule($this)->plugin('plugin.activated', $file);

    expect(written($this->journal)[0][1])->toBe('plugin:');
})->with([null, '', 4]);

it('writes a change of theme with the one it replaces', function (): void {
    $module = journalModule($this);
    $module->themeSwitched('New Theme', new FakeTheme('new'), new FakeTheme('old'));
    $module->themeSwitched('Only A Name');

    expect(written($this->journal))->toBe([['theme.switched', 'theme:new', ['from' => 'old']], ['theme.switched', 'theme:Only A Name', ['from' => '']]]);
});

it('writes what the upgrader updated or installed', function (array $extra, mixed $upgrader, array $expected): void {
    journalModule($this)->upgraded($upgrader, $extra);

    expect(array_map(static fn (array $one): array => [$one[0], $one[1]], written($this->journal)))->toBe($expected);
})->with([
    'several plugins' => [['type' => 'plugin', 'action' => 'update', 'plugins' => ['a/a.php', 'b/b.php']], null, [['plugin.updated', 'plugin:a'], ['plugin.updated', 'plugin:b']]],
    'one plugin' => [['type' => 'plugin', 'action' => 'update', 'plugin' => 'a/a.php'], null, [['plugin.updated', 'plugin:a']]],
    'several themes' => [['type' => 'theme', 'action' => 'update', 'themes' => ['one', 'two']], null, [['theme.updated', 'theme:one'], ['theme.updated', 'theme:two']]],
    'one theme' => [['type' => 'theme', 'action' => 'update', 'theme' => 'one'], null, [['theme.updated', 'theme:one']]],
    'a new plugin' => [['type' => 'plugin', 'action' => 'install'], new class
    {
        public function plugin_info(): string
        {
            return 'fresh/fresh.php';
        }
    }, [['plugin.installed', 'plugin:fresh']]],
    'a new theme' => [['type' => 'theme', 'action' => 'install'], new class
    {
        public function theme_info(): object
        {
            return new FakeTheme('shiny');
        }
    }, [['theme.installed', 'theme:shiny']]],
    'a theme the upgrader cannot name' => [['type' => 'theme', 'action' => 'install'], new stdClass, [['theme.installed', 'theme:']]],
    'a plugin with no upgrader' => [['type' => 'plugin', 'action' => 'install'], null, [['plugin.installed', 'plugin:']]],
    'a translation' => [['type' => 'translation', 'action' => 'update'], null, []],
    'something else done' => [['type' => 'plugin', 'action' => 'delete', 'plugins' => ['a/a.php']], null, []],
    'nothing said' => [[], null, []],
]);

it('writes an update of the core with the version it now has', function (): void {
    $before = $GLOBALS['wp_version'] ?? null;
    $GLOBALS['wp_version'] = '7.2';
    journalModule($this)->upgraded(null, ['type' => 'core', 'action' => 'update']);
    journalModule($this)->upgraded(null, 'nonsense');
    $GLOBALS['wp_version'] = $before;

    expect(written($this->journal))->toBe([['core.updated', 'core', ['version' => '7.2']]]);
});

it('writes a change of an option that decides who gets in, with its new value only when that is no secret', function (string $option, mixed $old, mixed $new, array $expected): void {
    journalModule($this)->optionChanged($option, $old, $new);

    expect(written($this->journal))->toBe($expected);
})->with([
    'the address of the site' => ['siteurl', 'a', 'https://b.example', [['option.changed', 'option:siteurl', ['to' => 'https://b.example']]]],
    'registration opened' => ['users_can_register', '0', true, [['option.changed', 'option:users_can_register', ['to' => 1]]]],
    'the default role' => ['default_role', 'subscriber', 'administrator', [['option.changed', 'option:default_role', ['to' => 'administrator']]]],
    'the e-mail of the site, never its value' => ['admin_email', 'a@example.com', 'b@example.com', [['option.changed', 'option:admin_email', ['changed' => true]]]],
    'not a scalar' => ['home', 'a', ['b'], [['option.changed', 'option:home', ['changed' => true]]]],
    'unchanged' => ['siteurl', 'a', 'a', []],
    'another option' => ['blogname', 'a', 'b', []],
]);

it('says how a scan ended: how many findings failed, and how many could not be verified', function (): void {
    (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable, ['a' => Finding::fail('a', Severity::High, 'x'), 'b' => Finding::fail('b', Severity::Low, 'y'), 'c' => Finding::unknown('c', 'z'), 'd' => Finding::pass('d', 'ok')]));
    journalModule($this)->scanCompleted();

    expect(written($this->journal))->toBe([['hornet.scan_completed', 'scan', ['failed' => 2, 'unknown' => 1]]]);
});

it('still notes that a scan ended when its result cannot be read', function (): void {
    Functions\when('get_option')->alias(static fn (): never => throw new RuntimeException('database gone'));
    journalModule($this)->scanCompleted();

    expect(written($this->journal))->toBe([['hornet.scan_completed', 'scan', ['failed' => 0, 'unknown' => 0]]]);
});
