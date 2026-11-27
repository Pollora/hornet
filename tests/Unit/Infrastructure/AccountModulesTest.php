<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Password\BreachCheck;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Infrastructure\Config\AccountSettings;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\PasswordModule;
use Pollora\Hornet\Infrastructure\WordPress\SessionModule;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorEnrollment;
use Pollora\Hornet\Infrastructure\WordPress\TwoFactorGateModule;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\FakeWpError;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

describe('account settings', function (): void {
    it('is off until it is asked for', function (): void {
        $accounts = Settings::fromArray([], new ArraySecrets)->accounts;

        expect($accounts->isActive())->toBeFalse()->and($accounts->checkBreached)->toBeFalse()->and($accounts->sessionLifetime)->toBeNull()->and($accounts->requireRoles)->toBe([]);
    });

    it('reads the rules', function (): void {
        $accounts = AccountSettings::fromConfig([
            'passwords' => ['check_breached' => true, 'breached_threshold' => 3],
            'session' => ['lifetime' => 3600, 'remember_lifetime' => 604800],
            'two_factor' => ['require_roles' => ['administrator', 'shop_manager'], 'enforce_after' => '2026-12-15'],
        ]);

        expect($accounts->isActive())->toBeTrue()->and($accounts->checkBreached)->toBeTrue()->and($accounts->breachedThreshold)->toBe(3)
            ->and($accounts->sessionLifetime)->toBe(3600)->and($accounts->rememberLifetime)->toBe(604800)
            ->and($accounts->requireRoles)->toBe(['administrator', 'shop_manager'])->and($accounts->enforceAfter?->format('Y-m-d'))->toBe('2026-12-15');
    });

    it('refuses what it does not understand', function (array $config, string $message): void {
        expect(fn () => AccountSettings::fromConfig($config))->toThrow(InvalidSettingsException::class, $message);
    })->with([
        'passwords not an array' => [['passwords' => true], 'passwords'],
        'unknown password key' => [['passwords' => ['breached' => true]], 'breached'],
        'check_breached not a bool' => [['passwords' => ['check_breached' => 'yes']], 'check_breached'],
        'threshold zero' => [['passwords' => ['breached_threshold' => 0]], 'breached_threshold'],
        'session unknown key' => [['session' => ['duration' => 3600]], 'duration'],
        'a session of one minute' => [['session' => ['lifetime' => 60]], 'session.lifetime'],
        'a session of a year' => [['session' => ['lifetime' => 31536000]], 'session.lifetime'],
        'a lifetime that is not a number' => [['session' => ['lifetime' => '1h']], 'session.lifetime'],
        'roles that are a string' => [['two_factor' => ['require_roles' => 'administrator']], 'require_roles'],
        'a role with a bad name' => [['two_factor' => ['require_roles' => ['Admin Istrator']]], 'require_roles'],
        'an unknown two_factor key' => [['two_factor' => ['required' => []]], 'required'],
        'a date that is not a date' => [['two_factor' => ['enforce_after' => 'soon']], 'YYYY-MM-DD'],
        'a date that does not exist' => [['two_factor' => ['enforce_after' => '2026-02-31']], 'YYYY-MM-DD'],
        'a date with a time' => [['two_factor' => ['enforce_after' => '2026-12-15 10:00']], 'YYYY-MM-DD'],
    ]);
});

describe('passwords', function (): void {
    function passwordModule(string $body = '', int $threshold = 1, int $status = 200): array
    {
        $http = new FakeHttpClient;
        $http->answers = [new HttpResponse($status, null, $body)];
        $hooks = new RecordingHooks;
        (new PasswordModule(new BreachCheck($http), $threshold))->register($hooks);

        return [$hooks, $http];
    }

    it('attaches to the profile form and the password reset form', function (): void {
        [$hooks] = passwordModule();

        expect(array_column($hooks->registered, 'hook'))->toBe(['user_profile_update_errors', 'validate_password_reset']);
    });

    it('refuses a breached password typed in the profile', function (): void {
        [$hooks] = passwordModule(PASSWORD_SUFFIX.':10');
        $errors = new FakeWpError;
        $hooks->callback('user_profile_update_errors')($errors, true, (object) ['user_pass' => 'password']);

        expect($errors->added)->toHaveCount(1)->and($errors->added[0][0])->toBe('hornet_breached_password')->and($errors->added[0][1])->toContain('known data breaches');
    });

    it('lets a password through that is not in a breach, or when the service cannot say', function (string $body, int $status): void {
        [$hooks] = passwordModule($body, 1, $status);
        $errors = new FakeWpError;
        $hooks->callback('user_profile_update_errors')($errors, true, (object) ['user_pass' => 'a-long-unique-passphrase']);

        expect($errors->added)->toBe([]);
    })->with([
        'not in the list' => ["0018A45C4D1DEF81644B54AB7F969B88D65:1\n", 200],
        'service down' => ['', 503],
    ]);

    it('only asks when a password was typed: a profile saved without one is left alone', function (): void {
        [$hooks, $http] = passwordModule(PASSWORD_SUFFIX.':10');
        $errors = new FakeWpError;
        $hooks->callback('user_profile_update_errors')($errors, true, (object) ['display_name' => 'x']);

        expect($errors->added)->toBe([])->and($http->gets)->toBe([]);
    });

    it('waits for the number of appearances the owner chose', function (): void {
        [$hooks] = passwordModule(PASSWORD_SUFFIX.':4', 5);
        $errors = new FakeWpError;
        $hooks->callback('user_profile_update_errors')($errors, true, (object) ['user_pass' => 'password']);

        expect($errors->added)->toBe([]);
    });

    it('reads the password of the reset form', function (): void {
        Functions\when('wp_unslash')->returnArg();
        [$hooks] = passwordModule(PASSWORD_SUFFIX.':10');
        $_POST['pass1'] = 'password';
        $errors = new FakeWpError;
        $hooks->callback('validate_password_reset')($errors);
        unset($_POST['pass1']);

        expect($errors->added)->toHaveCount(1);
    });

    it('does nothing on a reset form with no password', function (): void {
        [$hooks, $http] = passwordModule();
        $hooks->callback('validate_password_reset')(new FakeWpError);

        expect($http->gets)->toBe([]);
    });
});

describe('session', function (): void {
    it('shortens a login, and leaves the one it is not asked about', function (): void {
        $hooks = new RecordingHooks;
        (new SessionModule(3600, null))->register($hooks);
        $expiration = $hooks->callback('auth_cookie_expiration');

        expect($expiration(172800, 1, false))->toBe(3600)->and($expiration(1209600, 1, true))->toBe(1209600);
    });

    it('shortens the remembered login', function (): void {
        expect((new SessionModule(null, 86400))->expiration(1209600, 1, true))->toBe(86400)->and((new SessionModule(null, 86400))->expiration(172800, 1, false))->toBe(172800);
    });
});

describe('two-factor gate', function (): void {
    function gate(array $roles = ['administrator'], ?string $after = null, string $now = '2026-12-20', ?array &$sent = null): TwoFactorGateModule
    {
        $sent = [];
        $accounts = AccountSettings::fromConfig(['two_factor' => array_filter(['require_roles' => $roles, 'enforce_after' => $after])]);

        return new TwoFactorGateModule($accounts, new TwoFactorEnrollment, new FakeClock($now), static function (string $url) use (&$sent): void {
            $sent[] = $url;
        }, static fn (): ?object => $GLOBALS['hornet_user'] ?? null);
    }

    beforeEach(function (): void {
        Functions\when('is_multisite')->justReturn(false);
        Two_Factor_Core::$enabled = [];
        $GLOBALS['hornet_user'] = (object) ['ID' => 7, 'roles' => ['administrator']];
        $GLOBALS['pagenow'] = 'index.php';
        Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://example.com/wp-admin/'.$path);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('esc_url')->returnArg();
    });

    afterEach(function (): void {
        unset($GLOBALS['hornet_user'], $GLOBALS['pagenow']);
    });

    it('attaches to the start of every dashboard page and to its notices', function (): void {
        $hooks = new RecordingHooks;
        gate()->register($hooks);

        expect(array_column($hooks->registered, 'hook'))->toBe(['admin_init', 'admin_notices']);
    });

    it('sends a user of the role who has no method to the profile', function (): void {
        $module = gate(sent: $sent);
        $module->gate();

        expect($sent)->toBe(['https://example.com/wp-admin/profile.php?hornet_2fa=required#two-factor-options']);
    });

    it('lets in a user who has set up a method', function (): void {
        Two_Factor_Core::$enabled[7] = ['Two_Factor_Totp'];
        gate(sent: $sent)->gate();

        expect($sent)->toBe([]);
    });

    it('leaves the roles it was not told about', function (): void {
        $GLOBALS['hornet_user'] = (object) ['ID' => 8, 'roles' => ['editor']];
        gate(sent: $sent)->gate();

        expect($sent)->toBe([]);
    });

    it('never redirects before the date: it asks with a notice instead', function (): void {
        $module = gate(after: '2026-12-31', now: '2026-12-20', sent: $sent);
        $module->gate();
        ob_start();
        $module->notice();
        $notice = (string) ob_get_clean();

        expect($sent)->toBe([])->and($notice)->toContain('before 31 December 2026')->toContain('profile.php#two-factor-options');
    });

    it('redirects from the date on', function (): void {
        gate(after: '2026-12-15', now: '2026-12-15', sent: $sent)->gate();

        expect($sent)->toHaveCount(1);
    });

    it('keeps the profile and what it posts to open, so that the user can enroll', function (string $page): void {
        $GLOBALS['pagenow'] = $page;
        gate(sent: $sent)->gate();

        expect($sent)->toBe([]);
    })->with(['profile.php', 'admin-post.php', 'admin-ajax.php']);

    it('still redirects from the other pages', function (string $page): void {
        $GLOBALS['pagenow'] = $page;
        gate(sent: $sent)->gate();

        expect($sent)->toHaveCount(1);
    })->with(['index.php', 'plugins.php', 'user-edit.php', 'options-general.php']);

    it('does not touch ajax, cron, rest or the command line', function (string $kind): void {
        match ($kind) {
            'ajax' => Functions\when('wp_doing_ajax')->justReturn(true),
            'cron' => Functions\when('wp_doing_cron')->justReturn(true),
            default => null,
        };
        $module = gate(sent: $sent);
        $module->gate();

        expect($sent)->toBe([]);
    })->with(['ajax', 'cron']);

    it('does nothing when nobody is logged in, or when the plugin is not there', function (): void {
        unset($GLOBALS['hornet_user']);
        gate(sent: $sent)->gate();

        expect($sent)->toBe([]);
    });

    it('shows an enforced notice on the profile page', function (): void {
        $GLOBALS['pagenow'] = 'profile.php';
        $module = gate(sent: $sent);
        ob_start();
        $module->notice();

        expect((string) ob_get_clean())->toContain('must set up two-factor authentication')->not->toContain('before');
    });

    it('says nothing to a user who is not concerned', function (): void {
        Two_Factor_Core::$enabled[7] = ['Two_Factor_Email'];
        ob_start();
        gate()->notice();

        expect((string) ob_get_clean())->toBe('');
    });
});
