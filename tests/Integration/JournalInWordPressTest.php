<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalPrivacy;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalSchema;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\Journal\WpRequestContext;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;

const JOURNAL_HOOKS = ['init', 'wp_login', 'wp_login_failed', 'after_password_reset', 'user_register', 'delete_user', 'set_user_role', 'add_user_role', 'remove_user_role', 'grant_super_admin', 'revoke_super_admin', 'wp_create_application_password', 'wp_delete_application_password', 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme', 'deleted_theme', 'upgrader_process_complete', 'updated_option', 'pollora/hornet/scan_completed', 'pollora/hornet/purge_journal', 'wp_privacy_personal_data_exporters', 'wp_privacy_personal_data_erasers', 'admin_init'];

function journalEvents(?string $type = null, int $limit = 50): array
{
    return (new TableJournal)->find(new JournalQuery($type, limit: $limit));
}

beforeEach(function (): void {
    require_once ABSPATH.'wp-admin/includes/user.php';
    // WordPress and its plugins have hooks of their own on these: put back what was there
    $this->hooks = [];

    foreach (JOURNAL_HOOKS as $hook) {
        $this->hooks[$hook] = isset($GLOBALS['wp_filter'][$hook]) ? clone $GLOBALS['wp_filter'][$hook] : null;
    }

    JournalSchema::drop();
    JournalSchema::ensure();
    $this->journal = new TableJournal;
    $this->recorder = new JournalRecorder($this->journal, new WpRequestContext(static fn (): ClientIp => new ClientIp('203.0.113.50', '203.0.113.50')), new SystemClock);
    $this->module = new JournalModule($this->recorder, $this->journal, new SystemClock, 30);
});

afterEach(function (): void {
    foreach ($this->hooks as $hook => $saved) {
        $saved === null ? remove_all_actions($hook) : $GLOBALS['wp_filter'][$hook] = $saved;
    }

    wp_set_current_user(0);
    JournalSchema::drop();
    delete_option(OptionScanStore::OPTION);
});

describe('the table', function (): void {
    it('is made once, with its version, and not touched again', function (): void {
        global $wpdb;

        expect(get_option(JournalSchema::OPTION))->toBe(JournalSchema::VERSION)
            ->and($wpdb->get_var("SHOW TABLES LIKE '".JournalSchema::table()."'"))->toBe(JournalSchema::table())
            ->and(JournalSchema::table())->toBe($wpdb->prefix.'hornet_journal');

        $wpdb->insert(JournalSchema::table(), ['occurred_at' => '2026-01-01 00:00:00', 'type' => 'login.success', 'source' => 'web', 'details' => '{}']);
        JournalSchema::ensure();

        expect((int) $wpdb->get_var('SELECT COUNT(*) FROM '.JournalSchema::table()))->toBe(1);
    });

    it('is made again when it is missing, and removed with its option', function (): void {
        global $wpdb;

        // Dropped by hand, with its option: the version is what says the table is there
        $wpdb->query('DROP TABLE '.JournalSchema::table());
        delete_option(JournalSchema::OPTION);
        JournalSchema::ensure();

        expect($wpdb->get_var("SHOW TABLES LIKE '".JournalSchema::table()."'"))->toBe(JournalSchema::table());

        JournalSchema::drop();

        expect($wpdb->get_var("SHOW TABLES LIKE '".JournalSchema::table()."'"))->toBeNull()->and(get_option(JournalSchema::OPTION))->toBeFalse();
    });

    it('keeps nothing in the posts or their meta', function (): void {
        global $wpdb;
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}") + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");

        $this->recorder->record('login.success', 'user:1', [], 1, 'admin');

        expect((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}") + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"))->toBe($before);
    });
});

describe('the journal', function (): void {
    it('keeps an event whole, and reads it back, the newest first', function (): void {
        $this->recorder->record('plugin.activated', 'plugin:one');
        $this->recorder->record('user.role_changed', 'user:5', ['from' => 'editor', 'to' => 'administrator'], 1, 'admin');

        $events = journalEvents();

        expect($events)->toHaveCount(2)->and($events[0]->type)->toBe('user.role_changed')
            ->and($events[0])->toMatchObject(['userId' => 1, 'actor' => 'admin', 'subject' => 'user:5', 'ip' => '203.0.113.0', 'details' => ['from' => 'editor', 'to' => 'administrator']])
            ->and($events[0]->source)->toBe(Source::Web)
            ->and($events[0]->at->getTimezone()->getName())->toBe('UTC')
            ->and($events[0]->id)->toBeGreaterThan($events[1]->id ?? 0);
    });

    it('narrows by type, group, source, account and date, and counts', function (): void {
        $this->recorder->record('login.failed', 'user:2', [], 2, 'bob');
        $this->recorder->record('login.success', 'user:1', [], 1, 'admin');
        $this->recorder->record('plugin.activated', 'plugin:x', [], 1, 'admin');

        expect(array_map(static fn (Event $e): string => $e->type, journalEvents('login.')))->toBe(['login.success', 'login.failed'])
            ->and(journalEvents('login.failed'))->toHaveCount(1)
            ->and($this->journal->find(new JournalQuery(userId: 2)))->toHaveCount(1)
            ->and($this->journal->find(new JournalQuery(source: Source::Cli)))->toBe([])
            ->and($this->journal->find(new JournalQuery(source: Source::Web)))->toHaveCount(3)
            ->and($this->journal->find(new JournalQuery(since: new DateTimeImmutable('+1 day'))))->toBe([])
            ->and($this->journal->count(new JournalQuery))->toBe(3)
            ->and($this->journal->count(new JournalQuery(type: 'login.')))->toBe(2)
            ->and($this->journal->find(new JournalQuery(limit: 1, offset: 1)))->toHaveCount(1)
            ->and($this->journal->find(new JournalQuery(limit: 1, offset: 5)))->toBe([]);
    });

    it('does not let a value into the query as code', function (): void {
        $this->recorder->record('login.success', 'user:1', [], 1, "admin'; DROP TABLE x; --");

        foreach (["x' OR '1'='1", 'login.%', '%', "login.success' OR 1=1 -- ", 'login._uccess'] as $hostile) {
            expect($this->journal->find(new JournalQuery(type: $hostile)))->toBe([]);
        }

        expect(journalEvents())->toHaveCount(1)->and(journalEvents()[0]->actor)->toBe("admin'; DROP TABLE x; --");
    });

    it('forgets what is older than a date, and only that', function (): void {
        global $wpdb;
        $this->recorder->record('login.success', 'user:1');
        $wpdb->insert(JournalSchema::table(), ['occurred_at' => gmdate('Y-m-d H:i:s', time() - 40 * 86400), 'type' => 'login.failed', 'source' => 'web', 'details' => '{}']);
        $wpdb->insert(JournalSchema::table(), ['occurred_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400), 'type' => 'login.failed', 'source' => 'web', 'details' => '{}']);

        $removed = $this->module->purge();

        expect($removed)->toBe(1)->and(journalEvents())->toHaveCount(2);
    });

    it('puts the purge in the cron, once', function (): void {
        wp_clear_scheduled_hook(JournalModule::PURGE_HOOK);

        $this->module->plan();
        $this->module->plan();

        expect(wp_get_schedule(JournalModule::PURGE_HOOK))->toBe('daily');

        wp_clear_scheduled_hook(JournalModule::PURGE_HOOK);
    });

    it('never gets in the way when the table is gone', function (): void {
        JournalSchema::drop();
        $this->module->register(new WordPressHooks);
        $silence = static function (): void {};

        // WordPress would print the database error; the journal must not make a request fail
        global $wpdb;
        $wpdb->suppress_errors(true);

        do_action('wp_login_failed', 'nobody', new WP_Error('invalid_username'));

        $wpdb->suppress_errors(false);

        expect($this->recorder->failures())->toBeGreaterThan(0);
        $silence();
    });
});

describe('what it listens to', function (): void {
    beforeEach(function (): void {
        $this->module->register(new WordPressHooks);
    });

    it('writes a login, and a failure on an account, with the reason', function (): void {
        $user = get_user_by('id', 1);

        do_action('wp_login', $user->user_login, $user);
        do_action('wp_login_failed', $user->user_login, new WP_Error('incorrect_password'));

        $events = journalEvents('login.');

        expect($events)->toHaveCount(2)->and($events[0])->toMatchObject(['type' => 'login.failed', 'userId' => 1, 'subject' => 'user:1', 'details' => ['reason' => 'incorrect_password']])
            ->and($events[1])->toMatchObject(['type' => 'login.success', 'actor' => $user->user_login]);
    });

    it('does not write the name that was typed on a login that matches nobody, as it may be a password', function (): void {
        do_action('wp_login_failed', 'Tr0ub4dor&3-my-real-password', new WP_Error('invalid_username'));

        $event = journalEvents('login.failed')[0];

        expect($event)->toMatchObject(['userId' => 0, 'actor' => '', 'subject' => 'unknown account', 'details' => ['reason' => 'invalid_username']]);

        global $wpdb;

        expect((string) $wpdb->get_var('SELECT GROUP_CONCAT(CONCAT_WS(" ", actor, subject, details, ip)) FROM '.JournalSchema::table()))->not->toContain('Tr0ub4dor');
    });

    it('writes an account that is created, given a role, and deleted', function (): void {
        wp_set_current_user(1);
        $id = wp_insert_user(['user_login' => 'journal-test-'.wp_generate_password(5, false), 'user_pass' => wp_generate_password(), 'user_email' => 'journal-test-'.wp_generate_password(5, false).'@example.test', 'role' => 'subscriber']);
        $user = new WP_User($id);
        $user->set_role('editor');
        $user->add_role('author');
        $user->remove_role('author');
        $this->module->flush();
        wp_delete_user($id);

        $types = array_map(static fn (Event $e): array => [$e->type, $e->details], array_reverse(journalEvents('user.')));

        expect($types)->toBe([
            ['user.created', ['role' => 'subscriber']],
            ['user.role_changed', ['from' => 'subscriber', 'to' => 'editor']],
            ['user.role_changed', ['added' => 'author']],
            ['user.role_changed', ['removed' => 'author']],
            ['user.deleted', []],
        ]);
        // The one who did it is the one who is logged in
        expect(journalEvents('user.created')[0])->toMatchObject(['userId' => 1, 'subject' => 'user:'.$id]);
    });

    it('does not write the role of a user at their creation twice', function (): void {
        $id = wp_insert_user(['user_login' => 'journal-test-'.wp_generate_password(5, false), 'user_pass' => wp_generate_password(), 'user_email' => 'journal-test-'.wp_generate_password(5, false).'@example.test', 'role' => 'editor']);

        $this->module->flush();

        expect(array_map(static fn (Event $e): string => $e->type, journalEvents('user.')))->toBe(['user.created']);

        wp_delete_user($id);
    });

    it('writes a plugin that is activated and deactivated, a theme that is switched', function (): void {
        wp_set_current_user(1);
        // WordPress says a plugin is deactivated only when it was active
        activate_plugin('two-factor/two-factor.php');
        deactivate_plugins('two-factor/two-factor.php');
        activate_plugin('two-factor/two-factor.php');
        $theme = get_stylesheet();
        $other = array_values(array_diff(array_keys(wp_get_themes()), [$theme]))[0];
        switch_theme($other);
        switch_theme($theme);

        $events = array_map(static fn (Event $e): array => [$e->type, $e->subject], array_reverse(journalEvents()));

        expect($events)->toContain(['plugin.deactivated', 'plugin:two-factor'])->toContain(['plugin.activated', 'plugin:two-factor'])
            ->toContain(['theme.switched', 'theme:'.$other])->toContain(['theme.switched', 'theme:'.$theme]);
        expect(array_values(array_filter(journalEvents('theme.'), static fn (Event $e): bool => $e->subject === 'theme:'.$theme))[0]->details)->toBe(['from' => $other]);
    });

    it('writes what an updater did, by name', function (): void {
        $upgrader = new stdClass;

        do_action('upgrader_process_complete', $upgrader, ['type' => 'plugin', 'action' => 'update', 'plugins' => ['akismet/akismet.php', 'hello.php']]);
        do_action('upgrader_process_complete', $upgrader, ['type' => 'theme', 'action' => 'update', 'themes' => ['twentytwentyfive']]);
        do_action('upgrader_process_complete', $upgrader, ['type' => 'core', 'action' => 'update']);
        do_action('upgrader_process_complete', $upgrader, ['type' => 'plugin', 'action' => 'delete']);

        $events = array_map(static fn (Event $e): array => [$e->type, $e->subject], array_reverse(journalEvents()));

        expect($events)->toBe([['plugin.updated', 'plugin:akismet'], ['plugin.updated', 'plugin:hello'], ['theme.updated', 'theme:twentytwentyfive'], ['core.updated', 'core']]);
        expect(journalEvents('core.updated')[0]->details)->toBe(['version' => $GLOBALS['wp_version']]);
    });

    it('writes a sensitive option that changed, and not what a person owns', function (): void {
        $default = get_option('default_role');
        $email = get_option('admin_email');
        wp_set_current_user(1);

        update_option('default_role', 'editor');
        update_option('default_role', 'editor');
        update_option('admin_email', 'journal-test-new@example.test');
        update_option('blogdescription', 'not a sensitive option');

        $events = array_reverse(journalEvents('option.'));

        expect(array_map(static fn (Event $e): array => [$e->subject, $e->details], $events))->toBe([['option:default_role', ['to' => 'editor']], ['option:admin_email', ['changed' => true]]]);

        global $wpdb;

        expect((string) $wpdb->get_var('SELECT GROUP_CONCAT(details) FROM '.JournalSchema::table()))->not->toContain('journal-test-new');

        update_option('default_role', $default);
        update_option('admin_email', $email);
    });

    it('writes an application password by its name, never the password', function (): void {
        $result = WP_Application_Passwords::create_new_application_password(1, ['name' => 'ci-runner']);
        $password = $result[0];
        WP_Application_Passwords::delete_application_password(1, $result[1]['uuid']);

        $events = array_reverse(array_filter(journalEvents('user.'), static fn (Event $e): bool => str_contains($e->type, 'app_password')));

        expect(array_map(static fn (Event $e): array => [$e->type, $e->details], array_values($events)))->toBe([['user.app_password_created', ['name' => 'ci-runner']], ['user.app_password_deleted', ['name' => 'ci-runner']]]);

        global $wpdb;

        expect((string) $wpdb->get_var('SELECT GROUP_CONCAT(CONCAT_WS(" ", actor, subject, details)) FROM '.JournalSchema::table()))->not->toContain(str_replace(' ', '', $password))->not->toContain($password);
    });

    it('writes that a scan ended, with what it found', function (): void {
        (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable, ['core-integrity' => Finding::fail('core-integrity', Severity::High, 'x'), 'plugin-integrity' => Finding::unknown('plugin-integrity', 'y'), 'theme-integrity' => Finding::pass('theme-integrity', 'z')]));

        do_action('pollora/hornet/scan_completed');

        expect(journalEvents('hornet.')[0])->toMatchObject(['type' => 'hornet.scan_completed', 'subject' => 'scan', 'details' => ['failed' => 1, 'unknown' => 1]]);
    });
});

describe('the source of a request', function (): void {
    it('says web for an ordinary request, and gives the address of the client', function (): void {
        $context = new WpRequestContext(static fn (): ClientIp => new ClientIp('198.51.100.9', '198.51.100.9'));

        expect($context->source())->toBe(Source::Web)->and($context->ip())->toBe('198.51.100.9')->and($context->userId())->toBe(0)->and($context->userLogin())->toBe('');

        wp_set_current_user(1);

        expect($context->userId())->toBe(1)->and($context->userLogin())->not->toBe('');
    });

    it('gives no address when nobody is known', function (): void {
        expect((new WpRequestContext(static fn (): ?ClientIp => null))->ip())->toBe('');
    });
});

describe('the privacy tools of WordPress', function (): void {
    beforeEach(function (): void {
        $this->privacy = new JournalPrivacy($this->journal, 30);
        $this->privacy->register(new WordPressHooks);
        $this->id = wp_insert_user(['user_login' => 'journal-test-'.wp_generate_password(5, false), 'user_pass' => wp_generate_password(), 'user_email' => 'journal-privacy-'.wp_generate_password(5, false).'@example.test', 'role' => 'subscriber']);
        $this->email = get_userdata($this->id)->user_email;
        $this->recorder->record('login.success', 'user:'.$this->id, [], $this->id, 'someone');
        $this->recorder->record('user.role_changed', 'user:'.$this->id, ['to' => 'editor'], 1, 'admin');
        $this->recorder->record('plugin.activated', 'plugin:x', [], 1, 'admin');
    });

    afterEach(function (): void {
        wp_delete_user($this->id);
    });

    it('is among the exporters and the erasers WordPress knows', function (): void {
        expect(array_keys(apply_filters('wp_privacy_personal_data_exporters', [])))->toContain('hornet-journal')
            ->and(array_keys(apply_filters('wp_privacy_personal_data_erasers', [])))->toContain('hornet-journal');
    });

    it('exports the events that name a person: what they did and what was done to them, and nothing else', function (): void {
        $export = $this->privacy->export($this->email);

        expect($export['done'])->toBeTrue()->and($export['data'])->toHaveCount(2)
            ->and(array_column(array_column($export['data'], 'data')[0], 'name'))->toBe(['When', 'What', 'From', 'About', 'Network'])
            ->and(json_encode($export))->not->toContain('plugin:x')->toContain('203.0.113.0');
    });

    it('exports nothing for an address that is nobody\'s', function (): void {
        expect($this->privacy->export('nobody@example.test'))->toBe(['data' => [], 'done' => true]);
    });

    it('takes a person out of the events, and keeps the events', function (): void {
        $erased = $this->privacy->erase($this->email);

        expect($erased)->toMatchArray(['items_removed' => true, 'items_retained' => true, 'done' => true])
            ->and($erased['messages'])->not->toBe([])
            ->and(journalEvents())->toHaveCount(3)
            ->and($this->privacy->export($this->email)['data'])->toBe([]);

        global $wpdb;

        expect((string) $wpdb->get_var('SELECT GROUP_CONCAT(CONCAT_WS(" ", actor, subject, ip)) FROM '.JournalSchema::table().' WHERE type <> "plugin.activated"'))->not->toContain('someone')->not->toContain('user:'.$this->id)->toContain('user:erased');
    });

    it('says it removed nothing for an address that is nobody\'s', function (): void {
        expect($this->privacy->erase('nobody@example.test'))->toMatchArray(['items_removed' => false, 'items_retained' => false, 'messages' => []]);
    });

    it('pages the export', function (): void {
        for ($i = 0; $i < 105; $i++) {
            $this->recorder->record('login.success', 'user:'.$this->id, [], $this->id, 'someone');
        }

        $first = $this->privacy->export($this->email, 1);
        $second = $this->privacy->export($this->email, 2);

        expect($first['done'])->toBeFalse()->and($first['data'])->toHaveCount(100)->and($second['done'])->toBeTrue()->and($second['data'])->toHaveCount(7);
    });
});
