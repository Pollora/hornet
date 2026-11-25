<?php

declare(strict_types=1);

use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Checks\AdminAccountCheck;
use Pollora\Hornet\Domain\Audit\Checks\ConfigPermissionsCheck;
use Pollora\Hornet\Domain\Audit\Checks\DebugModeCheck;
use Pollora\Hornet\Domain\Audit\Checks\FileEditorCheck;
use Pollora\Hornet\Domain\Audit\Checks\FileModsCheck;
use Pollora\Hornet\Domain\Audit\Checks\HttpsCheck;
use Pollora\Hornet\Domain\Audit\Checks\LoginProtectionCheck;
use Pollora\Hornet\Domain\Audit\Checks\PhpVersionCheck;
use Pollora\Hornet\Domain\Audit\Checks\SaltsCheck;
use Pollora\Hornet\Domain\Audit\Checks\TwoFactorCheck;
use Pollora\Hornet\Domain\Audit\Checks\UserEnumerationCheck;
use Pollora\Hornet\Domain\Audit\Checks\WordPressUpdateCheck;
use Pollora\Hornet\Domain\Audit\Checks\XmlRpcCheck;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;

/**
 * @param  array<string, mixed>  $constants
 */
function verdict(Check $check, FakeEnvironment $environment): array
{
    $finding = $check->run($environment);

    return [$finding->outcome, $finding->severity];
}

$salt = str_repeat('x', 64);
$salts = array_fill_keys(['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'], $salt);

it('gives every check a kebab-case id that is unique', function (): void {
    $ids = array_map(static fn (Check $check): string => $check->id(), DefaultChecks::all());

    expect($ids)->toBe(array_unique($ids))
        ->and($ids)->each->toMatch('/^[a-z]+(-[a-z]+)*$/');
});

it('gives every check a label', function (): void {
    foreach (DefaultChecks::all() as $check) {
        expect($check->label())->not->toBe('');
    }
});

describe('salts', function () use ($salts): void {
    it('passes when the eight constants are long enough', function () use ($salts): void {
        expect(verdict(new SaltsCheck, FakeEnvironment::with($salts))[0])->toBe(Outcome::Pass);
    });

    it('fails and names the constants that are missing, short or the sample', function () use ($salts): void {
        $weak = [...$salts, 'AUTH_KEY' => 'short', 'NONCE_SALT' => 'Put Your Unique Phrase Here and more padding to pass the length', 'LOGGED_IN_KEY' => 42];
        unset($weak['AUTH_SALT']);

        $finding = (new SaltsCheck)->run(FakeEnvironment::with($weak));

        expect($finding->outcome)->toBe(Outcome::Fail)
            ->and($finding->severity)->toBe(Severity::High)
            ->and($finding->details)->toBe(['AUTH_KEY', 'LOGGED_IN_KEY', 'AUTH_SALT', 'NONCE_SALT']);
    });

    it('never prints a value', function () use ($salts): void {
        $finding = (new SaltsCheck)->run(FakeEnvironment::with([...$salts, 'AUTH_KEY' => 'tooshortsecretvalue']));

        expect(json_encode($finding->toArray()))->not->toContain('tooshortsecretvalue');
    });
});

describe('debug mode', function (): void {
    it('is not judged outside production', function (): void {
        expect(verdict(new DebugModeCheck, FakeEnvironment::with(['WP_DEBUG' => true], 'local'))[0])->toBe(Outcome::Pass);
    });

    it('rates errors shown in production as high', function (): void {
        expect(verdict(new DebugModeCheck, FakeEnvironment::with(['WP_DEBUG' => true])))->toBe([Outcome::Fail, Severity::High]);
    });

    it('rates WP_DEBUG with the display off as medium', function (): void {
        expect(verdict(new DebugModeCheck, FakeEnvironment::with(['WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false])))->toBe([Outcome::Fail, Severity::Medium]);
    });

    it('rates a public debug.log as low', function (): void {
        expect(verdict(new DebugModeCheck, FakeEnvironment::with(['WP_DEBUG_LOG' => true])))->toBe([Outcome::Fail, Severity::Low]);
    });

    it('passes when debug is off', function (): void {
        expect(verdict(new DebugModeCheck, FakeEnvironment::with(['WP_DEBUG' => false]))[0])->toBe(Outcome::Pass);
    });
});

describe('https', function (): void {
    it('is not judged outside production', function (): void {
        expect(verdict(new HttpsCheck, new FakeEnvironment(type: 'local', https: false))[0])->toBe(Outcome::Pass);
    });

    it('fails high without https', function (): void {
        expect(verdict(new HttpsCheck, new FakeEnvironment(https: false)))->toBe([Outcome::Fail, Severity::High]);
    });

    it('asks for FORCE_SSL_ADMIN as a constant', function (): void {
        expect(verdict(new HttpsCheck, new FakeEnvironment))->toBe([Outcome::Fail, Severity::Low]);
        expect(verdict(new HttpsCheck, FakeEnvironment::with(['FORCE_SSL_ADMIN' => true]))[0])->toBe(Outcome::Pass);
    });
});

describe('file editor and file mods', function (): void {
    it('reads whether the editor is refused, whoever refused it', function (): void {
        expect(verdict(new FileEditorCheck, new FakeEnvironment(editingBlocked: true))[0])->toBe(Outcome::Pass)
            ->and(verdict(new FileEditorCheck, new FakeEnvironment(editingBlocked: false)))->toBe([Outcome::Fail, Severity::Medium]);
    });

    it('passes when DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS is true', function (): void {
        expect(verdict(new FileEditorCheck, FakeEnvironment::with(['DISALLOW_FILE_EDIT' => true]))[0])->toBe(Outcome::Pass);
        expect(verdict(new FileEditorCheck, FakeEnvironment::with(['DISALLOW_FILE_MODS' => true]))[0])->toBe(Outcome::Pass);
    });

    it('fails medium when the editor is on, and says what pollora/headless does', function (): void {
        expect(verdict(new FileEditorCheck, new FakeEnvironment))->toBe([Outcome::Fail, Severity::Medium]);
        expect((new FileEditorCheck)->run(new FakeEnvironment(active: [Component::Headless]))->details)->not->toBe([]);
    });

    it('only advises on file mods, and only in production', function (): void {
        expect(verdict(new FileModsCheck, new FakeEnvironment))->toBe([Outcome::Fail, Severity::Info]);
        expect(verdict(new FileModsCheck, new FakeEnvironment(type: 'staging'))[0])->toBe(Outcome::Pass);
        expect(verdict(new FileModsCheck, FakeEnvironment::with(['DISALLOW_FILE_MODS' => true]))[0])->toBe(Outcome::Pass);
    });
});

describe('wp-config.php permissions', function (): void {
    it('cannot judge a file it cannot find or read', function (): void {
        expect(verdict(new ConfigPermissionsCheck, new FakeEnvironment(config: null))[0])->toBe(Outcome::Unknown);
        expect(verdict(new ConfigPermissionsCheck, new FakeEnvironment(mode: null))[0])->toBe(Outcome::Unknown);
    });

    it('rates a world-writable file as high, a world-readable one as low', function (): void {
        expect(verdict(new ConfigPermissionsCheck, new FakeEnvironment(mode: 0o666)))->toBe([Outcome::Fail, Severity::High]);
        expect(verdict(new ConfigPermissionsCheck, new FakeEnvironment(mode: 0o644)))->toBe([Outcome::Fail, Severity::Low]);
    });

    it('passes a private file and shows its mode', function (): void {
        $finding = (new ConfigPermissionsCheck)->run(new FakeEnvironment(mode: 0o640));

        expect($finding->outcome)->toBe(Outcome::Pass)->and($finding->details)->toBe(['mode 0640']);
    });
});

describe('php version', function (): void {
    it('fails a branch below 8.2 as high', function (): void {
        expect(verdict(new PhpVersionCheck, new FakeEnvironment(php: '8.1.30')))->toBe([Outcome::Fail, Severity::High]);
        expect(verdict(new PhpVersionCheck, new FakeEnvironment(php: '7.4.33')))->toBe([Outcome::Fail, Severity::High]);
    });

    it('warns six months before the end of security support', function (): void {
        expect(verdict(new PhpVersionCheck, new FakeEnvironment(php: '8.2.27', today: '2026-11-24')))->toBe([Outcome::Fail, Severity::Low]);
    });

    it('fails a branch whose support ended', function (): void {
        expect(verdict(new PhpVersionCheck, new FakeEnvironment(php: '8.2.27', today: '2027-01-02')))->toBe([Outcome::Fail, Severity::High]);
    });

    it('passes a branch with more than six months to go', function (): void {
        expect(verdict(new PhpVersionCheck, new FakeEnvironment(php: '8.4.1', today: '2026-11-24'))[0])->toBe(Outcome::Pass);
    });

    it('does not guess about a branch newer than its table', function (): void {
        expect(verdict(new PhpVersionCheck, new FakeEnvironment(php: '9.0.0'))[0])->toBe(Outcome::Unknown);
    });
});

describe('wordpress version', function (): void {
    it('passes without a pending update', function (): void {
        expect(verdict(new WordPressUpdateCheck, new FakeEnvironment)[0])->toBe(Outcome::Pass);
    });

    it('fails medium when an update waits, and names both versions', function (): void {
        $finding = (new WordPressUpdateCheck)->run(new FakeEnvironment(wordpress: '7.1.4', update: '7.1.5'));

        expect($finding->outcome)->toBe(Outcome::Fail)
            ->and($finding->severity)->toBe(Severity::Medium)
            ->and($finding->summary)->toContain('7.1.4')->toContain('7.1.5');
    });
});

describe('accounts and login', function (): void {
    it('flags a user named admin', function (): void {
        expect(verdict(new AdminAccountCheck, new FakeEnvironment(users: ['admin'])))->toBe([Outcome::Fail, Severity::Medium]);
        expect(verdict(new AdminAccountCheck, new FakeEnvironment)[0])->toBe(Outcome::Pass);
    });

    it('reads what XML-RPC answers: open, refusing logins only, or closed', function (): void {
        expect(verdict(new XmlRpcCheck, new FakeEnvironment(xmlRpc: XmlRpcState::Open)))->toBe([Outcome::Fail, Severity::Medium])
            ->and(verdict(new XmlRpcCheck, new FakeEnvironment(xmlRpc: XmlRpcState::LoginDisabled)))->toBe([Outcome::Fail, Severity::Low])
            ->and(verdict(new XmlRpcCheck, new FakeEnvironment(xmlRpc: XmlRpcState::Closed))[0])->toBe(Outcome::Pass);
    });

    it('does not call XML-RPC closed when only the logins are refused', function (): void {
        expect((new XmlRpcCheck)->run(new FakeEnvironment(xmlRpc: XmlRpcState::LoginDisabled))->summary)->toContain('pingbacks');
    });

    it('reads whether visitors can list the users', function (): void {
        expect(verdict(new UserEnumerationCheck, new FakeEnvironment(usersListed: true)))->toBe([Outcome::Fail, Severity::Medium])
            ->and(verdict(new UserEnumerationCheck, new FakeEnvironment(usersListed: false))[0])->toBe(Outcome::Pass)
            ->and(verdict(new UserEnumerationCheck, new FakeEnvironment(usersListed: null))[0])->toBe(Outcome::Unknown);
    });

    it('recognises pollora/portcullis and does not do its work again', function (): void {
        expect(verdict(new LoginProtectionCheck, new FakeEnvironment(active: [Component::Portcullis]))[0])->toBe(Outcome::Pass);
        expect(verdict(new LoginProtectionCheck, new FakeEnvironment))->toBe([Outcome::Fail, Severity::Low]);
    });

    it('recognises the Two-Factor plugin', function (): void {
        expect(verdict(new TwoFactorCheck, new FakeEnvironment(active: [Component::TwoFactor]))[0])->toBe(Outcome::Pass);
        expect(verdict(new TwoFactorCheck, new FakeEnvironment))->toBe([Outcome::Fail, Severity::Low]);
    });
});
