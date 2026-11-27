<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\LoginDates;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeApplicationPasswords;
use Pollora\Hornet\Tests\Fixtures\FakeRestResponse;
use Pollora\Hornet\Tests\Fixtures\FakeSessionTokens;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeTheme;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;

beforeEach(function (): void {
    $this->site = FakeSite::install();
    $this->db = $GLOBALS['wpdb'] = new FakeWpdb;
});

/** Two accounts of the network, whose numbers the database gives as text. */
function mutWpSuperAdmins(): void
{
    Functions\when('get_super_admins')->justReturn(['ghost', 'root', 'second']);
    Functions\when('get_user_by')->alias(static fn (string $field, string $value): mixed => match ($value) {
        'root' => (object) ['ID' => '9', 'user_registered' => '2024-05-05 00:00:00'],
        'second' => (object) ['ID' => '2', 'user_registered' => '2024-06-06 00:00:00'],
        default => false,
    });
}

function mutWpRow(string $type, string $subject, string $details, int $at): array
{
    return ['id' => '1', 'occurred_at' => gmdate('Y-m-d H:i:s', $at), 'type' => $type, 'source' => 'admin', 'details' => $details, 'subject' => $subject];
}

function mutWpRoles(): void
{
    Functions\when('get_role')->alias(static fn (string $name): ?object => $name === 'gone' ? null : new class($name)
    {
        /** @var array<int|string, bool> */
        public array $capabilities = ['read' => true, 'off' => false, 7 => true];

        public function __construct(private readonly string $name) {}

        public function has_cap(string $capability): bool
        {
            return $this->name === 'administrator' && $capability === 'manage_options';
        }
    });
}

it('reads no upgrade from a list that is not one', function (): void {
    $this->site->siteTransients['update_core'] = (object) ['updates' => (object) ['a' => (object) ['response' => 'upgrade', 'current' => '9.9']]];

    expect((new WordPressEnvironment)->availableCoreUpdate())->toBeNull();
});

it('asks whether XML-RPC logins are open starting from yes, as WordPress does', function (): void {
    Functions\when('apply_filters')->returnArg(2);

    expect((new WordPressEnvironment)->xmlRpcState())->toBe(XmlRpcState::Open);
});

it('asks about the file editor as nobody on a site, and as a super administrator on a network', function (bool $network, array $admins, int $asked): void {
    $this->site->multisite = $network;
    Functions\when('get_super_admins')->justReturn($admins);
    Functions\when('get_user_by')->justReturn((object) ['ID' => '9']);
    $users = [];
    Functions\when('map_meta_cap')->alias(function (string $capability, int $user) use (&$users): array {
        $users[] = $user;

        return ['do_not_allow'];
    });

    expect((new WordPressEnvironment)->fileEditingBlocked())->toBeTrue()->and($users)->toBe([$asked, $asked]);
})->with([
    'a single site' => [false, ['root'], 0],
    'a network' => [true, ['root'], 9],
    'a network without super administrator' => [true, [], 0],
]);

it('takes only a status below 400 for a list given to visitors', function (int $status, bool $listed): void {
    Functions\when('get_current_user_id')->justReturn(5);
    Functions\when('wp_set_current_user')->justReturn(null);
    Functions\when('rest_do_request')->justReturn(new FakeRestResponse([['id' => 1]], $status));

    expect((new WordPressEnvironment)->usersListedToVisitors())->toBe($listed);
})->with([[399, true], [400, false]]);

it('reads the note that the throttle left', function (): void {
    $this->site->options[LoginThrottleModule::PROBLEM_OPTION] = ['reason' => 'shared', 'at' => time()];

    expect((new WordPressEnvironment)->loginThrottleProblem())->toBe('shared');
});

it('names the super administrators once, in a plain list, when it looks for a missing second factor', function (): void {
    $this->site->multisite = true;
    mutWpSuperAdmins();
    Functions\when('get_users')->justReturn(['x', '9', '3']);

    expect((new WordPressEnvironment)->usersWithoutTwoFactor())->toBe([9, 3, 2]);
});

it('lists the application passwords of the super administrators of a network too, once each', function (): void {
    $this->site->multisite = true;
    mutWpSuperAdmins();
    Functions\when('home_url')->justReturn('https://example.com');
    Functions\when('wp_get_environment_type')->justReturn('production');
    Functions\when('apply_filters')->returnArg(2);
    $query = null;
    Functions\when('get_users')->alias(function (array $arguments) use (&$query): array {
        $query = $arguments;

        return ['9', '1'];
    });
    FakeApplicationPasswords::$passwords = [1 => [['created' => 1]], 2 => [['created' => 2]], 9 => [['created' => 9]]];

    expect(array_column((new WordPressEnvironment)->administratorApplicationPasswords(), 'user'))->toBe([9, 1, 2])
        ->and($query)->toBe(['capability' => 'manage_options', 'meta_key' => '_application_passwords', 'fields' => 'ID', 'number' => 500]);
});

it('says which role a visitor gets by registering, with the capabilities it has', function (): void {
    $this->site->options['users_can_register'] = '1';
    $this->site->options['default_role'] = 'subscriber';
    mutWpRoles();

    expect((new WordPressEnvironment)->registrationRole())->toBe(['role' => 'subscriber', 'capabilities' => ['read', '7']]);
});

it('says no role when nobody can register here, or the role is unknown', function (array $options, bool $network): void {
    $this->site->multisite = $network;
    $this->site->options = $options;
    mutWpRoles();

    expect((new WordPressEnvironment)->registrationRole())->toBeNull();
})->with([
    'registration closed' => [['default_role' => 'subscriber'], false],
    'a network' => [['default_role' => 'subscriber', 'users_can_register' => '1'], true],
    'no default role' => [['default_role' => '', 'users_can_register' => '1'], false],
    'a role that is gone' => [['default_role' => 'gone', 'users_can_register' => '1'], false],
]);

it('names themes by text even when their folder is a number, and leaves out the own ones', function (): void {
    Functions\when('wp_get_themes')->justReturn(['2020' => new FakeTheme('2020', '1.0'), '2021' => new FakeTheme('2021', ['odd'])]);
    $this->site->siteTransients['update_themes'] = (object) ['last_checked' => 5, 'response' => ['2021' => ['new_version' => '2.0']]];

    expect((new WordPressEnvironment(Settings::fromArray(['scanner' => ['own_themes' => ['2020']]])))->themeUpdates())
        ->toBe(['checked' => 5, 'waiting' => [['slug' => '2021', 'installed' => '', 'available' => '2.0']], 'unfollowed' => 0])
        ->and((new WordPressEnvironment)->themeUpdates()['unfollowed'])->toBe(1);

    Functions\when('get_stylesheet')->justReturn('a');
    Functions\when('get_template')->justReturn('a');
    $asked = [];
    Functions\when('wp_get_theme')->alias(function (string $slug) use (&$asked): FakeTheme {
        $asked[] = $slug;

        return new FakeTheme('a');
    });

    expect((new WordPressEnvironment)->unusedThemes())->toBe(['2020', '2021'])->and($asked)->toBe(['']);
});

it('names plugins whose file is a number, and counts only the unknown ones as unfollowed', function (): void {
    Functions\when('is_plugin_active')->justReturn(false);
    Functions\when('get_plugins')->justReturn(['5' => ['Version' => '1.0'], 'applied/applied.php' => ['Version' => '2.0'], 'a/a.php' => [], 'b/b.php' => []]);
    $this->site->siteTransients['update_plugins'] = (object) ['last_checked' => 1, 'response' => ['applied/applied.php' => (object) ['new_version' => '2.0']]];

    expect((new WordPressEnvironment)->pluginUpdates())->toBe(['checked' => 1, 'waiting' => [], 'unfollowed' => 3])
        ->and((new WordPressEnvironment)->inactivePlugins())->toBe(['5', 'applied', 'a', 'b']);
});

it('asks for the administrators with what it needs of them, and skips what is not an account', function (): void {
    $query = null;
    Functions\when('get_users')->alias(function (array $arguments) use (&$query): array {
        $query = $arguments;

        return [(object) ['ID' => 'x'], 'text', (object) ['ID' => '4', 'user_registered' => '2025-01-01 00:00:00']];
    });
    date_default_timezone_set('Pacific/Auckland');

    try {
        $administrators = (new WordPressEnvironment(Settings::fromArray([])))->administrators();
    } finally {
        date_default_timezone_set('UTC');
    }

    expect($query)->toBe(['capability' => 'manage_options', 'fields' => ['ID', 'user_registered'], 'number' => 500])
        // The date of registration is in UTC whatever the zone of PHP, and no journal is read when it is off
        ->and($administrators)->toBe([['user' => 4, 'since' => 1735689600, 'last_seen' => null]])
        ->and($this->db->ran)->toBe([]);
});

it('takes the latest of what each source says for the last time an administrator was seen', function (array $meta, array $sessions, array $passwords, ?int $seen): void {
    Functions\when('get_users')->justReturn([(object) ['ID' => '4', 'user_registered' => '2025-01-01 00:00:00']]);
    $this->site->userMeta[4] = $meta;
    FakeSessionTokens::$sessions = [4 => $sessions];
    FakeApplicationPasswords::$passwords = [4 => $passwords];

    expect((new WordPressEnvironment)->administrators()[0]['last_seen'])->toBe($seen);
})->with([
    'the noted login alone' => [[LoginDates::META => '700'], [], [], 700],
    'an application password last' => [[LoginDates::META => '100'], [['login' => 200]], [['last_used' => '900']], 900],
    'a session without a date' => [[], [['expiration' => 5]], [['created' => 8]], null],
]);

it('dates an administrator from the latest time it was given the keys', function (): void {
    $now = time();
    Functions\when('get_users')->justReturn([
        (object) ['ID' => '1', 'user_registered' => '2025-01-01 00:00:00'],
        (object) ['ID' => '2', 'user_registered' => '2025-01-01 00:00:00'],
        (object) ['ID' => '3', 'user_registered' => '2025-01-01 00:00:00'],
    ]);
    mutWpRoles();
    $this->db->answers = [
        [
            mutWpRow(EventTypes::USER_ROLE, 'user:1', '{"added":"administrator"}', $now - 100),
            mutWpRow(EventTypes::USER_ROLE, 'user:1', '{"to":"administrator"}', $now - 5000),
            mutWpRow(EventTypes::USER_ROLE, 'user:3', '{"added":"gone"}', $now - 100),
        ],
        [mutWpRow(EventTypes::SUPER_ADMIN_GRANTED, 'user:2', '[]', $now - 200)],
    ];

    $since = array_column((new WordPressEnvironment(Settings::fromArray(['journal' => ['enabled' => true]])))->administrators(), 'since', 'user');

    expect($since)->toBe([1 => $now - 100, 2 => $now - 200, 3 => 1735689600]);
});

it('counts the super administrators of a network whose numbers come as text', function (): void {
    $this->site->multisite = true;
    mutWpSuperAdmins();
    Functions\when('get_users')->justReturn([]);

    expect(array_column((new WordPressEnvironment)->administrators(), 'user'))->toBe([9, 2]);
});
