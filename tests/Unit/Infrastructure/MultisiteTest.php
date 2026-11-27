<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Infrastructure\Config\AccountSettings;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Login\TransientAttemptStore;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\Journal\DroppedSiteModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalSchema;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\NetworkScan;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorEnrollment;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorGateModule;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WpCli\ScanCommand;
use Pollora\Hornet\Tests\Fixtures\FakeApplicationPasswords;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeRequestContext;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryWindowCounter;
use Pollora\Hornet\Tests\Fixtures\RecordingOutput;

/*
 * What differs on a multisite network. A real network is exercised by tests/E2E/run-multisite.sh.
 */
beforeEach(function (): void {
    $this->site = FakeSite::install();
    $this->site->multisite = true;
    // Account 9 is a super administrator with no role on the site being looked at
    $super = FakeWpUser::named(9, 'root');
    Functions\when('get_super_admins')->justReturn(['root', 'gone']);
    Functions\when('get_user_by')->alias(static fn (string $field, string $value): mixed => $value === 'root' ? $super : false);
    Functions\when('is_super_admin')->alias(static fn (int $id): bool => $id === 9);
});

afterEach(function (): void {
    unset($GLOBALS['pagenow']);
});

it('asks whether a super administrator may edit files: asked of nobody, a network always says no', function (): void {
    $asked = [];
    // As WordPress answers on a network: only a super administrator is not refused
    Functions\when('map_meta_cap')->alias(function (string $capability, int $user) use (&$asked): array {
        $asked[] = $user;

        return [$user === 9 ? $capability : 'do_not_allow'];
    });

    expect((new WordPressEnvironment)->fileEditingBlocked())->toBeFalse()->and($asked)->toContain(9);
});

it('counts the super administrators among the administrators without a second factor', function (): void {
    Functions\when('get_users')->justReturn(['2']);
    Two_Factor_Core::$enabled = [];

    expect((new WordPressEnvironment)->usersWithoutTwoFactor())->toBe([2, 9]);
});

it('does not add them when the roles asked about are not the administrators', function (): void {
    Functions\when('get_users')->justReturn(['2']);
    $settings = Settings::fromArray(['two_factor' => ['require_roles' => ['editor']]]);

    expect((new WordPressEnvironment($settings))->usersWithoutTwoFactor())->toBe([2]);
});

it('reads the application passwords of the super administrators too', function (): void {
    Functions\when('apply_filters')->justReturn(true);
    Functions\when('home_url')->justReturn('https://example.com');
    Functions\when('get_users')->justReturn([]);
    FakeApplicationPasswords::$passwords = [9 => [['created' => 100]]];

    expect((new WordPressEnvironment)->administratorApplicationPasswords())->toBe([['user' => 9, 'created' => 100, 'last_used' => null]]);
});

it('sends a super administrator without a role on the site to set up a second factor', function (int $id, array $sentTo): void {
    $GLOBALS['pagenow'] = 'index.php';
    Functions\when('admin_url')->alias(static fn (string $path = ''): string => '/wp-admin/'.$path);
    Functions\when('wp_doing_ajax')->justReturn(false);
    Functions\when('wp_doing_cron')->justReturn(false);
    Two_Factor_Core::$enabled = [];
    $sent = [];

    (new TwoFactorGateModule(
        AccountSettings::fromConfig(['two_factor' => ['require_roles' => ['administrator']]]),
        new TwoFactorEnrollment,
        new FakeClock,
        static function (string $url) use (&$sent): void {
            $sent[] = $url;
        },
        static fn (): object => (object) ['ID' => $id, 'roles' => []],
    ))->gate();

    expect($sent)->toBe($sentTo);
})->with([
    'a super administrator' => [9, ['/wp-admin/profile.php?hornet_2fa=required#two-factor-options']],
    'somebody with no role' => [4, []],
]);

it('keeps the failures and the locks of an address for the whole network', function (): void {
    $store = new TransientAttemptStore;
    $key = str_repeat('a', 64);
    $store->put($key, new Attempts(0, 0, time() + 600, 1), 900);

    // Nothing in the options of the site that happened to be asked: another site would not see it
    expect($this->site->globalGroups)->toBe(['hornet-login'])
        ->and($this->site->transients)->toBe([])
        ->and($this->site->options)->toBe([])
        ->and($this->site->siteTransients)->toHaveKey('hornet_lg_'.$key)
        ->and($this->site->networkOptions)->toHaveKey('hornet_lockouts')
        ->and($store->get($key)->lockedUntil)->toBeGreaterThan(time())
        ->and($store->forgetAll())->toBe(1)
        ->and($this->site->siteTransients)->toBe([])
        ->and($this->site->networkOptions)->toBe([]);
});

it('forgets one address for the whole network', function (): void {
    $store = new TransientAttemptStore;
    $key = str_repeat('b', 64);
    $store->put($key, new Attempts(0, 0, time() + 600, 1), 900);
    $store->forget($key);

    expect($this->site->siteTransients)->toBe([])->and($this->site->networkOptions)->toBe([]);
});

describe('the journal of a network', function (): void {
    beforeEach(function (): void {
        $this->journal = new InMemoryJournal;
        $this->current = 1;
        $this->switches = [];
        $member = FakeWpUser::named(3, 'ana');
        $member->roles = ['editor', 'author'];
        Functions\when('get_userdata')->alias(static fn (int $id): mixed => $id === 3 ? $member : false);
        Functions\when('get_current_blog_id')->alias(fn (): int => $this->current);
        Functions\when('switch_to_blog')->alias(function (int $site): void {
            $this->switches[] = $site;
            $this->current = $site;
        });
        Functions\when('restore_current_blog')->alias(function (): void {
            $this->switches[] = 'back';
            $this->current = 1;
        });
        $this->module = new JournalModule(new JournalRecorder($this->journal, new FakeRequestContext, new FakeClock), $this->journal, new FakeClock, 30, new FloodGuard(new InMemoryWindowCounter), static fn (): string => '');
        $this->written = fn (): array => array_map(static fn (Event $event): array => [$event->type, $event->subject, $event->details], $this->journal->events);
    });

    it('says that an account was deleted from the network, and not that it was when it only left a site', function (): void {
        $this->module->userDeleted(3);

        expect(($this->written)())->toBe([]);

        $this->module->networkUserDeleted(3);

        expect(($this->written)())->toBe([['user.deleted', 'user:3', []]]);
    });

    it('says which roles an account had on the site it is removed from', function (): void {
        $this->module->removedFromSite(3);
        $this->module->removedFromSite(8);

        expect(($this->written)())->toBe([['user.role_changed', 'user:3', ['removed' => 'editor,author']]]);
    });

    it('says that an account arrived on a site with a role', function (): void {
        $this->current = 2;
        $this->module->roleSet(3, 'administrator', []);
        $this->module->addedToSite(3, 'administrator', 2);

        expect(($this->written)())->toBe([['user.role_changed', 'user:3', ['added' => 'administrator']]]);
    });

    it('does not tell an arrival twice, nor one that changed nothing', function (): void {
        // It had a role there: roleSet() tells the change
        $this->module->roleSet(3, 'administrator', ['editor']);
        $this->module->addedToSite(3, 'administrator', 1);
        // It already had that very role: WordPress changes nothing and still says it was added
        $this->module->addedToSite(3, 'administrator', 1);

        expect(($this->written)())->toBe([['user.role_changed', 'user:3', ['from' => 'editor', 'to' => 'administrator']]]);
    });

    it('writes a role that was added on another site there, although the request ends on this one', function (): void {
        $this->current = 3;
        $this->module->roleAdded(3, 'administrator');
        $this->current = 1;
        $this->module->flush();

        expect($this->switches)->toBe([3, 'back'])->and(($this->written)())->toHaveCount(1);
    });

    it('makes the table of another site before it writes there', function (): void {
        $GLOBALS['wpdb'] = $db = new FakeWpdb;
        $switched = false;
        Functions\when('ms_is_switched')->alias(function () use (&$switched): bool {
            return $switched;
        });
        $made = [];
        Functions\when('dbDelta')->alias(function (string $sql) use (&$made): array {
            $made[] = $sql;

            return [];
        });
        $event = new Event(new DateTimeImmutable, 'login.success', Source::Web);

        (new TableJournal)->record($event);

        expect($made)->toBe([]);

        $switched = true;
        (new TableJournal)->record($event);

        expect($made)->toHaveCount(1)
            ->and($this->site->options)->toHaveKey(JournalSchema::OPTION)
            ->and($db->inserted)->toHaveCount(2);
    });
});

it('drops the journal table with a site, even from a site that keeps no journal', function (): void {
    Functions\when('is_admin')->justReturn(false);
    Functions\when('is_main_site')->justReturn(false);
    Functions\when('wp_next_scheduled')->justReturn(false);
    $GLOBALS['wpdb'] = new FakeWpdb;

    $classes = array_map(static fn (object $module): string => $module::class, Bootstrap::modules(Settings::fromArray([]), new FakeEnvironment));
    $module = new DroppedSiteModule;

    expect($classes)->toContain(DroppedSiteModule::class)
        ->and($module->tables(['a' => 'wp_7_posts'], '7'))->toBe(['a' => 'wp_7_posts', 'wp_7_hornet_journal'])
        ->and($module->tables(null, null))->toBe(['wp_hornet_journal']);
});

it('refuses to scan from a site that is not the main one', function (): void {
    Functions\when('is_main_site')->justReturn(false);
    Functions\when('get_main_site_id')->justReturn(1);
    Functions\when('get_home_url')->justReturn('https://example.com');
    $output = new RecordingOutput;
    $built = false;

    $command = new ScanCommand(function () use (&$built): ?Scanner {
        $built = true;

        return null;
    }, $output, NetworkScan::refusal(...));

    expect(fn () => $command())->toThrow(RuntimeException::class, 'nothing was scanned. Run it again with --url=https://example.com')
        ->and($built)->toBeFalse();
});

it('scans from the main site and from a single site', function (bool $multisite, bool $main): void {
    $this->site->multisite = $multisite;
    Functions\when('is_main_site')->justReturn($main);

    expect(NetworkScan::refusal())->toBeNull();
})->with([[true, true], [false, false]]);
