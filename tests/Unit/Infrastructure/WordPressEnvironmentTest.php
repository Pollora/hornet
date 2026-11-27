<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\LoginDates;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\ThrottleProblemNote;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeApplicationPasswords;
use Pollora\Hornet\Tests\Fixtures\FakeRestResponse;
use Pollora\Hornet\Tests\Fixtures\FakeSessionTokens;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeTheme;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;

beforeEach(function (): void {
    $this->site = FakeSite::install();
});

it('reads the constants of the install, and null for one that is not there', function (): void {
    $environment = new WordPressEnvironment;

    expect($environment->isDefined('ARRAY_A'))->toBeTrue()
        ->and($environment->constant('ARRAY_A'))->toBe('ARRAY_A')
        ->and($environment->isDefined('HORNET_NOT_THERE'))->toBeFalse()
        ->and($environment->constant('HORNET_NOT_THERE'))->toBeNull();
});

it('says what runs', function (): void {
    Functions\when('wp_get_environment_type')->justReturn('staging');
    Functions\when('wp_get_wp_version')->justReturn('7.1.2');
    $environment = new WordPressEnvironment;

    expect($environment->environmentType())->toBe('staging')
        ->and($environment->wordpressVersion())->toBe('7.1.2')
        ->and($environment->phpVersion())->toBe(PHP_VERSION)
        ->and($environment->now()->getTimezone()->getName())->toBe('UTC')
        ->and($environment->now()->getTimestamp())->toBeGreaterThan(time() - 5);
});

it('says the version WordPress offers to upgrade to, and none from what it cannot read', function (mixed $stored, ?string $expected): void {
    $this->site->siteTransients['update_core'] = $stored;

    expect((new WordPressEnvironment)->availableCoreUpdate())->toBe($expected);
})->with([
    'an upgrade' => [(object) ['updates' => ['x', (object) ['response' => 'latest', 'current' => '7.0'], (object) ['response' => 'upgrade', 'current' => '7.2']]], '7.2'],
    'up to date' => [(object) ['updates' => [(object) ['response' => 'latest', 'current' => '7.1']]], null],
    'a version that is not text' => [(object) ['updates' => [(object) ['response' => 'upgrade', 'current' => 7]]], null],
    'nothing stored' => [false, null],
    'no list' => [(object) ['updates' => 'x'], null],
]);

it('tells an https site from its address', function (string $home, bool $https): void {
    Functions\when('home_url')->justReturn($home);

    expect((new WordPressEnvironment)->usesHttps())->toBe($https);
})->with([['https://example.com', true], ['http://example.com', false]]);

it('finds wp-config.php in the root, then one level above, and reads its permissions', function (): void {
    $environment = new WordPressEnvironment;
    $above = dirname(ABSPATH).'/wp-config.php';

    expect($environment->configFile())->toBeNull();

    // Above the root, where only a file of this test goes: the temporary folder of the system is not ours to fill
    if (! file_exists($above)) {
        file_put_contents($above, '<?php');
        expect($environment->configFile())->toBe($above);
        unlink($above);
    }

    file_put_contents(ABSPATH.'wp-config.php', '<?php');
    chmod(ABSPATH.'wp-config.php', 0o640);

    expect($environment->configFile())->toBe(ABSPATH.'wp-config.php')
        ->and($environment->permissions(ABSPATH.'wp-config.php'))->toBe(0o640)
        ->and($environment->permissions(ABSPATH.'missing.php'))->toBeNull()
        ->and($environment->permissions(ABSPATH))->toBeNull();

    unlink(ABSPATH.'wp-config.php');
});

it('knows whether a login exists', function (): void {
    Functions\when('get_user_by')->alias(static fn (string $field, string $value): mixed => $field === 'login' && $value === 'admin' ? FakeWpUser::named(1, 'admin') : false);

    expect((new WordPressEnvironment)->userExists('admin'))->toBeTrue()->and((new WordPressEnvironment)->userExists('root'))->toBeFalse();
});

it('tells XML-RPC closed from login-disabled from open', function (bool $methods, bool $enabled, XmlRpcState $expected): void {
    Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => match ($hook) {
        'xmlrpc_methods' => $methods ? $value : [],
        'xmlrpc_enabled' => $enabled,
        default => $value,
    });

    expect((new WordPressEnvironment)->xmlRpcState())->toBe($expected);
})->with([
    'no method' => [false, true, XmlRpcState::Closed],
    'no login' => [true, false, XmlRpcState::LoginDisabled],
    'open' => [true, true, XmlRpcState::Open],
]);

it('says file editing is blocked only when plugins and themes both are', function (array $blocked, bool $expected): void {
    Functions\when('map_meta_cap')->alias(static fn (string $capability): array => in_array($capability, $blocked, true) ? ['do_not_allow'] : [$capability]);

    expect((new WordPressEnvironment)->fileEditingBlocked())->toBe($expected);
})->with([
    [['edit_plugins', 'edit_themes'], true],
    [['edit_plugins'], false],
    [['edit_themes'], false],
    [[], false],
]);

describe('the users, as a visitor would be told them', function (): void {
    beforeEach(function (): void {
        $this->current = [];
        Functions\when('get_current_user_id')->justReturn(5);
        Functions\when('wp_set_current_user')->alias(function (int $id): void {
            $this->current[] = $id;
        });
    });

    it('asks as nobody, then gives the session back', function (int $status, mixed $data, bool $listed): void {
        $asked = null;
        Functions\when('rest_do_request')->alias(function (object $request) use (&$asked, $status, $data): FakeRestResponse {
            $asked = [$request->method, $request->route, $this->current];

            return new FakeRestResponse($data, $status);
        });

        expect((new WordPressEnvironment)->usersListedToVisitors())->toBe($listed)
            ->and($asked)->toBe(['GET', '/wp/v2/users', [0]])
            ->and($this->current)->toBe([0, 5]);
    })->with([
        'a list' => [200, [['id' => 1]], true],
        'refused' => [401, ['code' => 'x'], false],
        'an empty list' => [200, [], false],
        'not a list' => [200, 'text', false],
    ]);

    it('cannot say when the request fails, and still gives the session back', function (): void {
        Functions\when('rest_do_request')->alias(static fn (): never => throw new RuntimeException('boom'));

        expect((new WordPressEnvironment)->usersListedToVisitors())->toBeNull()->and($this->current)->toBe([0, 5]);
    });

    it('asks the API when the REST restriction leaves the users open, or Hornet is off', function (array $config): void {
        Functions\when('rest_do_request')->justReturn(new FakeRestResponse([['id' => 1]]));

        expect((new WordPressEnvironment(Settings::fromArray($config)))->usersListedToVisitors())->toBeTrue();
    })->with([
        'allowed by the owner' => [['hardening' => ['restrict_rest_api' => true, 'rest_api_allow' => ['wp/v2/users']]]],
        'Hornet off' => [['enabled' => false, 'hardening' => ['restrict_rest_api' => true]]],
        'no restriction' => [[]],
    ]);
});

it('counts Hornet\'s own throttle and the Two-Factor plugin as active', function (): void {
    $on = new WordPressEnvironment(Settings::fromArray(['login' => ['throttle' => true]]));
    $off = new WordPressEnvironment(Settings::fromArray([]));

    expect($on->isActive(Component::HornetThrottle))->toBeTrue()
        ->and($off->isActive(Component::HornetThrottle))->toBeFalse()
        ->and((new WordPressEnvironment)->isActive(Component::HornetThrottle))->toBeFalse()
        ->and($off->isActive(Component::TwoFactor))->toBeTrue()
        ->and($off->isActive(Component::Portcullis))->toBeFalse()
        ->and($off->isActive(Component::Headless))->toBeFalse();
});

it('lists the accounts that have no second factor, among the roles that must have one', function (array $config, array $roles): void {
    $query = null;
    Functions\when('get_users')->alias(function (array $arguments) use (&$query): array {
        $query = $arguments;

        return ['1', '2', '3'];
    });
    Two_Factor_Core::$enabled = [2 => ['Two_Factor_Totp']];

    expect((new WordPressEnvironment(Settings::fromArray($config)))->usersWithoutTwoFactor())->toBe([1, 3])
        ->and($query)->toBe(['role__in' => $roles, 'fields' => 'ID', 'number' => 500]);
})->with([
    'the administrators by default' => [[], ['administrator']],
    'the roles of the settings' => [['two_factor' => ['require_roles' => ['editor', 'shop_manager']]], ['editor', 'shop_manager']],
]);

it('gives the throttle problem and the display of errors that were noted', function (): void {
    $this->site->options['hornet_throttle_problem'] = ['reason' => 'shared', 'at' => time()];
    $this->site->networkOptions['hornet_error_display'] = 'on';
    $environment = new WordPressEnvironment;

    expect($environment->loginThrottleProblem())->toBe((new ThrottleProblemNote)->read())
        ->and($environment->displaysPhpErrors())->toBeTrue();
});

it('gives no role by registration when the default role is not set', function (): void {
    $this->site->options['users_can_register'] = '1';

    expect((new WordPressEnvironment)->registrationRole())->toBeNull();
});

it('lists the application passwords of the administrators, without the passwords', function (): void {
    Functions\when('home_url')->justReturn('https://example.com');
    Functions\when('wp_get_environment_type')->justReturn('production');
    Functions\when('apply_filters')->returnArg(2);
    $query = null;
    Functions\when('get_users')->alias(function (array $arguments) use (&$query): array {
        $query = $arguments;

        return ['1', '2'];
    });
    FakeApplicationPasswords::$passwords = [1 => [['created' => '100', 'last_used' => '200', 'password' => 'hashed'], ['name' => 'x']]];

    expect((new WordPressEnvironment)->administratorApplicationPasswords())->toBe([
        ['user' => 1, 'created' => 100, 'last_used' => 200],
        ['user' => 1, 'created' => 0, 'last_used' => null],
    ])->and($query['capability'])->toBe('manage_options')->and($query['meta_key'])->toBe('_application_passwords');
});

it('offers application passwords on a local site without https', function (): void {
    Functions\when('home_url')->justReturn('http://example.test');
    Functions\when('wp_get_environment_type')->justReturn('local');
    Functions\when('apply_filters')->returnArg(2);
    Functions\when('get_users')->justReturn([]);

    expect((new WordPressEnvironment)->administratorApplicationPasswords())->toBe([]);
});

describe('the updates that wait', function (): void {
    beforeEach(function (): void {
        Functions\when('is_plugin_active')->alias(static fn (string $file): bool => $file === 'akismet/akismet.php');
        Functions\when('get_plugins')->justReturn([
            'akismet/akismet.php' => ['Version' => '5.0'],
            'applied/applied.php' => ['Version' => '2.0'],
            'premium/premium.php' => ['Version' => '1.0'],
            'hello.php' => ['Version' => '1.0'],
        ]);
    });

    it('are the offers newer than what is installed; an applied one is not waiting, and an unknown plugin is unfollowed', function (): void {
        $this->site->siteTransients['update_plugins'] = (object) [
            'last_checked' => 1000,
            'response' => ['akismet/akismet.php' => (object) ['new_version' => '5.1'], 'applied/applied.php' => (object) ['new_version' => '2.0']],
            'no_update' => ['hello.php' => (object) []],
        ];

        expect((new WordPressEnvironment)->pluginUpdates())->toBe(['checked' => 1000, 'waiting' => [['slug' => 'akismet', 'installed' => '5.0', 'available' => '5.1']], 'unfollowed' => 1]);
    });

    it('are not known when WordPress never looked', function (mixed $stored): void {
        $this->site->siteTransients['update_plugins'] = $stored;

        expect((new WordPressEnvironment)->pluginUpdates())->toBeNull();
    })->with([false, (object) ['response' => []], (object) ['last_checked' => '1000']]);

    it('are those of the themes that are not written for the site', function (): void {
        Functions\when('wp_get_themes')->justReturn(['mine' => new FakeTheme('mine', '1.0'), 'astra' => new FakeTheme('astra', '4.0'), 'odd' => new FakeTheme('odd', false)]);
        $this->site->siteTransients['update_themes'] = (object) ['last_checked' => 5, 'response' => ['astra' => ['new_version' => '4.1'], 'mine' => ['new_version' => '9']], 'no_update' => ['odd' => []]];
        $settings = Settings::fromArray(['scanner' => ['own_themes' => ['mine']]]);

        expect((new WordPressEnvironment($settings))->themeUpdates())->toBe(['checked' => 5, 'waiting' => [['slug' => 'astra', 'installed' => '4.0', 'available' => '4.1']], 'unfollowed' => 0]);
    });

    it('names the inactive plugins by their folder, and does not say for a network', function (): void {
        expect((new WordPressEnvironment)->inactivePlugins())->toBe(['applied', 'premium', 'hello']);

        $this->site->multisite = true;

        expect((new WordPressEnvironment)->inactivePlugins())->toBeNull()->and((new WordPressEnvironment)->unusedThemes())->toBeNull();
    });
});

it('names the themes that are neither active, nor the parent, nor the one to fall back on', function (bool $defaultInstalled): void {
    Functions\when('get_stylesheet')->justReturn('child');
    Functions\when('get_template')->justReturn('parent');
    Functions\when('wp_get_theme')->justReturn(new FakeTheme('twentydefault', '1', $defaultInstalled));
    Functions\when('wp_get_themes')->justReturn(['child' => 1, 'parent' => 1, 'twentydefault' => 1, 'older' => 1, 'unused' => 1]);
    FakeTheme::$coreDefault = new FakeTheme('older');

    expect((new WordPressEnvironment)->unusedThemes())->toBe($defaultInstalled ? ['older', 'unused'] : ['twentydefault', 'unused']);
})->with([true, false]);

it('keeps only the active themes when there is no theme to fall back on', function (): void {
    Functions\when('get_stylesheet')->justReturn('a');
    Functions\when('get_template')->justReturn('a');
    Functions\when('wp_get_theme')->justReturn(new FakeTheme('', '', false));
    Functions\when('wp_get_themes')->justReturn(['a' => 1, 'b' => 1]);

    expect((new WordPressEnvironment)->unusedThemes())->toBe(['b']);
});

describe('the administrators', function (): void {
    beforeEach(function (): void {
        $this->db = $GLOBALS['wpdb'] = new FakeWpdb;
        Functions\when('get_users')->justReturn([(object) ['ID' => '1', 'user_registered' => '2025-01-01 00:00:00'], (object) ['ID' => '2', 'user_registered' => 'garbage']]);
    });

    function stamp(string $time): int
    {
        return (int) strtotime($time.' UTC');
    }

    it('are dated by their account, and last seen at the latest of what is known', function (): void {
        $this->site->userMeta[1][LoginDates::META] = '100';
        FakeSessionTokens::$sessions = [1 => [['login' => 300], ['expiration' => 5]]];
        FakeApplicationPasswords::$passwords = [1 => [['last_used' => 200], ['created' => 999999]]];

        expect((new WordPressEnvironment)->administrators())->toBe([
            ['user' => 1, 'since' => stamp('2025-01-01 00:00:00'), 'last_seen' => 300],
            ['user' => 2, 'since' => 0, 'last_seen' => null],
        ])->and($this->db->ran)->toBe([]);
    });

    it('count the super administrators of a network too', function (): void {
        $this->site->multisite = true;
        Functions\when('get_super_admins')->justReturn(['root', 'ghost']);
        $root = FakeWpUser::named(9, 'root');
        $root->user_registered = '2024-05-05 00:00:00';
        Functions\when('get_user_by')->alias(static fn (string $field, string $value): mixed => $value === 'root' ? $root : false);

        expect(array_column((new WordPressEnvironment)->administrators(), 'user'))->toBe([1, 2, 9]);
    });

    it('take from the journal, when it is on, the last login and when an older account was given the keys', function (): void {
        $recent = gmdate('Y-m-d H:i:s', time() - 86400);
        $earlier = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        $row = static fn (string $type, string $subject, string $details, string $at): array => ['id' => '1', 'occurred_at' => $at, 'type' => $type, 'source' => 'admin', 'details' => $details, 'subject' => $subject];
        $this->db->answers = [
            // The changes of role, then the super administrators, then the last login of each
            [
                $row(EventTypes::USER_ROLE, 'user:1', '{"to":"administrator"}', $earlier),
                $row(EventTypes::USER_ROLE, 'user:1', '{"to":"editor"}', $recent),
                $row(EventTypes::USER_ROLE, 'user:7', '{}', $recent),
                $row(EventTypes::USER_ROLE, 'not a user', '{"added":"administrator"}', $recent),
            ],
            [$row(EventTypes::SUPER_ADMIN_GRANTED, 'user:2', '[]', $recent)],
            [$row(EventTypes::LOGIN_SUCCESS, 'user:1', '[]', $recent)],
            [],
        ];
        Functions\when('get_role')->alias(static fn (string $name): object => new class($name)
        {
            public function __construct(private readonly string $name) {}

            public function has_cap(string $capability): bool
            {
                return $this->name === 'administrator' && $capability === 'manage_options';
            }
        });

        $administrators = (new WordPressEnvironment(Settings::fromArray(['journal' => ['enabled' => true]])))->administrators();

        expect($administrators)->toBe([
            ['user' => 1, 'since' => stamp($earlier), 'last_seen' => stamp($recent)],
            ['user' => 2, 'since' => stamp($recent), 'last_seen' => null],
        ])
            ->and($this->db->ran[0])->toContain("type = 'user.role_changed'", 'occurred_at >=', 'LIMIT 200')
            ->and($this->db->ran[1])->toContain("type = 'user.super_admin_granted'")
            ->and($this->db->ran[2])->toContain("type = 'login.success'", 'user_id = 1', 'LIMIT 1');
    });
});

it('says since when the logins are noted, and what the last scan found', function (): void {
    $environment = new WordPressEnvironment;

    expect($environment->loginsNotedSince())->toBeNull()->and($environment->lastScan())->toBeNull();

    $this->site->networkOptions[LoginDates::SINCE] = 500;
    (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable('@700'), []));

    expect($environment->loginsNotedSince())->toBe(500)->and($environment->lastScan()?->at->getTimestamp())->toBe(700);
});
