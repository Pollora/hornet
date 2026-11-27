<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Checks\ApplicationPasswordsCheck;
use Pollora\Hornet\Domain\Audit\Checks\ConfigPermissionsCheck;
use Pollora\Hornet\Domain\Audit\Checks\DebugModeCheck;
use Pollora\Hornet\Domain\Audit\Checks\DormantAdministratorsCheck;
use Pollora\Hornet\Domain\Audit\Checks\InactiveExtensionsCheck;
use Pollora\Hornet\Domain\Audit\Checks\LoginProtectionCheck;
use Pollora\Hornet\Domain\Audit\Checks\NewAdministratorsCheck;
use Pollora\Hornet\Domain\Audit\Checks\PhpVersionCheck;
use Pollora\Hornet\Domain\Audit\Checks\SaltsCheck;
use Pollora\Hornet\Domain\Audit\Checks\TwoFactorCoverageCheck;
use Pollora\Hornet\Domain\Audit\Checks\UpdatesCheck;
use Pollora\Hornet\Domain\Audit\Checks\UserEnumerationCheck;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\SecurityScore;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Tests\Fixtures\ArrayTranslator;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;

/*
 * The limits of the audit: days, counts, lists that are cut, and the number made of it all.
 * The date of FakeEnvironment is 2026-11-24 at noon.
 */

$ago = static fn (int $days): int => (new DateTimeImmutable('2026-11-24 12:00:00'))->sub(new DateInterval("P{$days}D"))->getTimestamp();

it('gives the facts that prove a finding', function (Closure $finding, array $details): void {
    expect($finding()->details)->toBe($details);
})->with([
    'a shared address' => [fn (): Finding => (new LoginProtectionCheck)->run(new FakeEnvironment(active: [Component::HornetThrottle], throttleProblem: 'shared')), ['Locking that address would lock every visitor out at once.']],
    'a forwarding header' => [fn (): Finding => (new LoginProtectionCheck)->run(new FakeEnvironment(active: [Component::HornetThrottle], throttleProblem: 'forwarded-untrusted')), ['Requests are counted under the address that opened the connection, which a header cannot change.']],
    'no protection' => [fn (): Finding => (new LoginProtectionCheck)->run(new FakeEnvironment), ['Another plugin or the server may protect the login: Hornet only knows pollora/portcullis and its own throttle.']],
    'a file anyone writes' => [fn (): Finding => (new ConfigPermissionsCheck)->run(new FakeEnvironment(mode: 0o642)), ['mode 0642']],
    'a file anyone reads' => [fn (): Finding => (new ConfigPermissionsCheck)->run(new FakeEnvironment(mode: 0o644)), ['mode 0644']],
    'errors shown' => [fn (): Finding => (new DebugModeCheck)->run(FakeEnvironment::with(['WP_DEBUG' => true])), ['WP_DEBUG is on and WP_DEBUG_DISPLAY is not false']],
]);

it('does not mind that others may execute wp-config.php', function (): void {
    expect((new ConfigPermissionsCheck)->run(new FakeEnvironment(mode: 0o641))->outcome)->toBe(Outcome::Pass);
});

it('asks for keys and salts of 32 characters at least', function (int $length, Outcome $outcome): void {
    $constants = array_map(static fn (): string => str_repeat('k', $length), FakeEnvironment::salts());

    expect((new SaltsCheck)->run(FakeEnvironment::with($constants))->outcome)->toBe($outcome);
})->with([[31, Outcome::Fail], [32, Outcome::Pass]]);

describe('the end of the security fixes of PHP 8.3, on 2027-12-31', function (): void {
    $at = static function (string $moment): FakeEnvironment {
        return new class($moment) extends FakeEnvironment
        {
            public function __construct(private readonly string $moment)
            {
                parent::__construct(php: '8.3.0');
            }

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->moment);
            }
        };
    };

    it('is judged to the day', function (string $moment, Outcome $outcome, Severity $severity) use ($at): void {
        $finding = (new PhpVersionCheck)->run($at($moment));

        expect([$finding->outcome, $finding->severity])->toBe([$outcome, $severity]);
    })->with([
        'the last day' => ['2027-12-31 12:00:00', Outcome::Fail, Severity::Low],
        'just after the last day' => ['2028-01-01 00:00:00', Outcome::Fail, Severity::High],
        '180 days before' => ['2027-07-04 12:00:00', Outcome::Fail, Severity::Low],
        '180 days and some hours before' => ['2027-07-04 00:00:00', Outcome::Fail, Severity::Low],
        '181 days before' => ['2027-07-03 23:59:59', Outcome::Pass, Severity::Info],
    ]);
});

describe('lists of accounts and extensions', function () use ($ago): void {
    $passwords = static fn (int $accounts): array => array_map(static fn (int $user): array => ['user' => $user, 'created' => $ago(200), 'last_used' => null], range(1, $accounts));
    $admins = static fn (int $count, int $since): array => $count === 0 ? [] : array_map(static fn (int $user): array => ['user' => $user, 'since' => $since, 'last_seen' => null], range(1, $count));
    $updates = static fn (int $count): array => ['checked' => $ago(0), 'unfollowed' => 0, 'waiting' => array_map(static fn (int $i): array => ['slug' => "s{$i}", 'installed' => '1', 'available' => '2'], range(1, $count))];

    $lists = [
        'application passwords' => fn (int $count): Finding => (new ApplicationPasswordsCheck)->run(new FakeEnvironment(applicationPasswords: $passwords($count))),
        'new administrators' => fn (int $count): Finding => (new NewAdministratorsCheck)->run(new FakeEnvironment(administrators: [...$admins($count, $ago(1)), ['user' => 99, 'since' => $ago(100), 'last_seen' => $ago(1)]])),
        'dormant administrators' => fn (int $count): Finding => (new DormantAdministratorsCheck)->run(new FakeEnvironment(administrators: $admins($count, $ago(200)), loginsNotedSince: $ago(200))),
        'two factor' => fn (int $count): Finding => (new TwoFactorCoverageCheck)->run(new FakeEnvironment(withoutTwoFactor: range(1, $count))),
        'inactive plugins' => fn (int $count): Finding => InactiveExtensionsCheck::plugins()->run(new FakeEnvironment(inactivePlugins: array_map(static fn (int $i): string => "p{$i}", range(1, $count)))),
        'plugin updates' => fn (int $count): Finding => UpdatesCheck::plugins()->run(new FakeEnvironment(pluginUpdates: $updates($count))),
    ];

    foreach ($lists as $name => $run) {
        it("names five {$name} and counts the rest, in the right grammatical number", function () use ($run): void {
            $french = new ArrayTranslator(['and %d more' => ['%d autre', '%d autres']]);

            expect($run(5)->details)->toHaveCount(5)
                ->and(implode(' ', $run(5)->details))->not->toContain('more')
                ->and($run(7)->details)->toHaveCount(6)
                ->and($run(7)->details[5])->toBe('and 2 more')
                ->and($run(6)->detailMessages()[5]->render($french))->toBe('1 autre');
        });
    }
});

it('calls an application password forgotten only past 90 days', function () use ($ago): void {
    $environment = new FakeEnvironment(applicationPasswords: [['user' => 1, 'created' => $ago(300), 'last_used' => $ago(90)]]);

    expect((new ApplicationPasswordsCheck)->run($environment)->outcome)->toBe(Outcome::Pass);
});

it('does not call new an account that is exactly 14 days old', function () use ($ago): void {
    $environment = new FakeEnvironment(administrators: [['user' => 1, 'since' => $ago(14), 'last_seen' => null], ['user' => 2, 'since' => $ago(100), 'last_seen' => null]]);

    expect((new NewAdministratorsCheck)->run($environment)->outcome)->toBe(Outcome::Pass);
});

it('judges a dormant account to the second', function (int $since, ?int $seen, ?int $noted, Outcome $outcome): void {
    $environment = new FakeEnvironment(administrators: [['user' => 1, 'since' => $since, 'last_seen' => $seen]], loginsNotedSince: $noted);

    expect((new DormantAdministratorsCheck)->run($environment)->outcome)->toBe($outcome);
})->with([
    'watched for exactly 90 days' => [$ago(200), null, $ago(90), Outcome::Fail],
    'an account exactly 90 days old, never seen' => [$ago(90), null, $ago(200), Outcome::Fail],
    'seen exactly 90 days ago' => [$ago(200), $ago(90), $ago(200), Outcome::Pass],
]);

it('goes on to the dormant accounts after a recent one', function () use ($ago): void {
    $environment = new FakeEnvironment(
        administrators: [['user' => 1, 'since' => $ago(1), 'last_seen' => null], ['user' => 2, 'since' => $ago(200), 'last_seen' => null]],
        loginsNotedSince: $ago(200),
    );

    expect((new DormantAdministratorsCheck)->run($environment)->details)->toBe(['user #2']);
});

it('speaks of plugins or of themes, not of one for the other', function (): void {
    $stale = new FakeEnvironment(pluginUpdates: null, themeUpdates: null);

    expect(UpdatesCheck::themes()->label())->toBe('Theme updates')
        ->and(UpdatesCheck::plugins()->label())->toBe('Plugin updates')
        ->and(UpdatesCheck::themes()->run($stale)->summary)->toContain('theme updates')
        ->and(UpdatesCheck::plugins()->run($stale)->summary)->toContain('plugin updates')
        ->and(InactiveExtensionsCheck::themes()->label())->toBe('Inactive themes')
        ->and(InactiveExtensionsCheck::plugins()->label())->toBe('Inactive plugins')
        ->and(InactiveExtensionsCheck::themes()->run(new FakeEnvironment(unusedThemes: ['t']))->summary)->toBe('1 theme is installed and not used.')
        ->and(InactiveExtensionsCheck::plugins()->run(new FakeEnvironment(inactivePlugins: ['p']))->summary)->toBe('1 plugin is installed and not active.');
});

it('says nothing of unfollowed plugins when there are none', function (): void {
    expect(UpdatesCheck::plugins()->run(new FakeEnvironment)->details)->toBe([]);
});

it('translates a line of the details whatever its key', function (): void {
    $finding = Finding::pass('x', 'ok', [3 => Message::of('line')]);

    expect($finding->detailMessages()[0]->render(new ArrayTranslator(['line' => 'ligne'])))->toBe('ligne');
});

it('refuses a stored finding with a severity it does not know', function (): void {
    expect(Finding::fromArray(['check' => 'x', 'summary' => 's', 'outcome' => 'fail', 'severity' => 'huge']))->toBeNull();
});

it('stores nothing about languages when there is nothing to translate', function (): void {
    $finding = Finding::pass('x', 'plain');

    expect($finding->toStoredArray())->toBe($finding->toArray());
});

it('stores the translatable lines as plain data', function (): void {
    expect(Finding::pass('x', 'ok', [Message::of('line')])->toStoredArray()['i18n'])->toBe(['details' => [['one' => 'line']]]);
});

it('ranks the severities one above the other', function (): void {
    expect(Severity::Info->atLeast(Severity::Low))->toBeFalse()
        ->and(Severity::Low->atLeast(Severity::Low))->toBeTrue();
});

it('counts every outcome, those with nothing too', function (): void {
    expect((new Report([Finding::pass('a', 'ok')]))->counts())->toBe(['total' => 1, 'pass' => 1, 'open' => 0, 'ignored' => 0, 'unknown' => 0]);
});

it('gives each grade from its first point', function (array $severities, int $value, string $grade): void {
    $findings = [];

    foreach ($severities as $index => $severity) {
        $findings[] = Finding::fail("c{$index}", $severity, 'x');
    }

    $score = SecurityScore::of(new Report($findings));

    expect([$score->value, $score->grade])->toBe([$value, $grade]);
})->with([
    'A at 90' => [array_fill(0, 10, Severity::Info), 90, 'A'],
    'B at 75' => [[Severity::Medium, Severity::Medium, Severity::Medium, Severity::Info], 75, 'B'],
    'C at 60' => [array_fill(0, 5, Severity::Medium), 60, 'C'],
    'D at 40' => [array_fill(0, 3, Severity::High), 40, 'D'],
    'E at 20' => [array_fill(0, 4, Severity::High), 20, 'E'],
]);

it('reads every finding, whatever comes before it, and says all of the score', function (): void {
    $score = SecurityScore::of(new Report([
        Finding::unknown('u', 'x'),
        Finding::pass('p', 'x'),
        Finding::fail('i', Severity::High, 'x')->ignoredFor('known'),
        Finding::fail('o', Severity::Medium, 'x'),
    ]));

    expect($score->toArray())->toBe(['value' => 89, 'grade' => 'B', 'provisional' => true, 'verified' => 3, 'total' => 4, 'accepted' => 1, 'costs' => ['o' => 8]]);
});

it('reads a list of updates that is exactly seven days old', function () use ($ago): void {
    $environment = new FakeEnvironment(pluginUpdates: ['checked' => $ago(7), 'unfollowed' => 0, 'waiting' => []]);

    expect(UpdatesCheck::plugins()->run($environment)->outcome)->toBe(Outcome::Pass);
});

it('gives the themes and the plugins their own identifier and good news', function (): void {
    expect(UpdatesCheck::themes()->id())->toBe('theme-updates')
        ->and(UpdatesCheck::plugins()->id())->toBe('plugin-updates')
        ->and(UpdatesCheck::themes()->run(new FakeEnvironment)->summary)->toBe('No theme update is waiting.')
        ->and(UpdatesCheck::plugins()->run(new FakeEnvironment)->summary)->toBe('No plugin update is waiting.')
        ->and(InactiveExtensionsCheck::themes()->id())->toBe('inactive-themes')
        ->and(InactiveExtensionsCheck::plugins()->id())->toBe('inactive-plugins')
        ->and(InactiveExtensionsCheck::themes()->run(new FakeEnvironment)->summary)->toBe('No theme is installed without a use.')
        ->and(InactiveExtensionsCheck::plugins()->run(new FakeEnvironment)->summary)->toBe('Every installed plugin is active.');
});

it('says that no administrator has an application password, rather than count zero of them', function (): void {
    expect((new ApplicationPasswordsCheck)->run(new FakeEnvironment)->summary)->toBe('No administrator has an application password.');
});

it('proves that the users are listed', function (): void {
    expect((new UserEnumerationCheck)->run(new FakeEnvironment(usersListed: true))->details)->toBe(['/wp/v2/users answers an anonymous request']);
});
