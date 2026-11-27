<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\AuditorFactory;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Infrastructure\WordPress\ScannerFactory;
use Pollora\Hornet\Tests\Fixtures\AdminSite;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;

const SCAN_ALWAYS = ['core-integrity', 'plugin-integrity', 'abandoned-plugins', 'theme-integrity', 'suspicious-files'];

beforeEach(function (): void {
    $this->site = FakeSite::install();
    AdminSite::stubScanner();
});

describe('the auditor of the site', function (): void {
    function reasons(Settings $settings): array
    {
        $reasons = [];

        foreach (AuditorFactory::forWordPress($settings)->run(new FakeEnvironment(https: false))->findings as $finding) {
            if ($finding->ignoredReason !== null) {
                $reasons[$finding->checkId] = $finding->ignoredReason;
            }
        }

        return $reasons;
    }

    it('sets aside what the screen and the file set aside, and the file wins', function (): void {
        (new OptionSetAsideStore)->put(new SetAside('https', 'From the screen.', 1, 'a', 1));
        (new OptionSetAsideStore)->put(new SetAside('salts', 'From the screen too.', 1, 'a', 1));

        expect(reasons(Settings::fromArray(['ignore' => [['check' => 'https', 'reason' => 'From the file.']]])))->toEqualCanonicalizing(['https' => 'From the file.', 'salts' => 'From the screen too.']);
    });

    it('leaves out a finding set aside for a check that is not there any more, instead of refusing it', function (): void {
        (new OptionSetAsideStore)->put(new SetAside('composer-vulnerabilities', 'Was on once.', 1, 'a', 1));

        expect(reasons(Settings::fromArray([])))->toBe([]);
    });

    it('does not run what the settings turn off', function (): void {
        $ids = array_map(static fn ($check): string => $check->id(), AuditorFactory::forWordPress(Settings::fromArray(['disabled_checks' => ['https']]))->checks());

        expect($ids)->not->toContain('https')->toContain('salts');
    });
});

describe('the scanner of the site', function (): void {
    function scanner(array $config = [], bool $cron = false): ?Scanner
    {
        return ScannerFactory::forWordPress(Settings::fromArray($config), $cron);
    }

    function inside(Scanner $scanner, string $property): mixed
    {
        return (new ReflectionProperty(Scanner::class, $property))->getValue($scanner);
    }

    it('reads the files, then asks the site what it gives away, unless the probes are off', function (): void {
        expect(scanner()?->tasks())->toBe([...SCAN_ALWAYS, 'exposed-files', 'security-headers'])
            ->and(scanner(['scanner' => ['probes' => false]])?->tasks())->toBe(SCAN_ALWAYS);
    });

    it('reads the database when WordPress has one to read', function (): void {
        $GLOBALS['wpdb'] = new FakeWpdb;

        expect(scanner(['scanner' => ['probes' => false]])?->tasks())->toBe([...SCAN_ALWAYS, 'database-scripts']);
    });

    it('asks about vulnerabilities only on the two opt-ins', function (array $vulnerabilities, array $added): void {
        expect(scanner(['scanner' => ['probes' => false], 'vulnerabilities' => $vulnerabilities])?->tasks())->toBe([...SCAN_ALWAYS, ...$added]);
    })->with([
        'off' => [[], []],
        'the feed' => [['enabled' => true], ['vulnerabilities']],
        'the packages too' => [['enabled' => true, 'composer' => true], ['vulnerabilities', 'composer-vulnerabilities']],
        'the packages without the feed' => [['composer' => true], []],
    ]);

    it('does not build a task that is turned off, and builds no scanner when all are', function (): void {
        expect(scanner(['disabled_checks' => ['core-integrity', 'abandoned-plugins']])?->tasks())->not->toContain('core-integrity', 'abandoned-plugins')->toContain('plugin-integrity')
            ->and(scanner(['disabled_checks' => [...SCAN_ALWAYS, 'exposed-files', 'security-headers']]))->toBeNull();
    });

    it('passes on the findings that the file sets aside, and what to say before each task', function (): void {
        $progress = static fn (string $task) => null;
        $scanner = ScannerFactory::forWordPress(Settings::fromArray(['ignore' => [['check' => 'core-integrity', 'reason' => 'Patched on purpose.']]]), false, $progress);

        expect(inside($scanner, 'ignored'))->toBe(['core-integrity' => 'Patched on purpose.'])
            ->and(inside($scanner, 'progress'))->toBe($progress);
    });

    it('gives a run of the cron half of the time of the request, and a run by hand no limit', function (string $limit, int $box): void {
        $before = (string) ini_get('max_execution_time');
        ini_set('max_execution_time', $limit);

        try {
            expect(inside(scanner(cron: true), 'timeBox'))->toBe($box)->and(inside(scanner(), 'timeBox'))->toBeNull();
        } finally {
            ini_set('max_execution_time', $before);
        }
    })->with([
        'no limit: 45 seconds' => ['0', 22],
        'the usual 30' => ['30', 11],
        'a very short one is not under 10' => ['12', 5],
        'a long one is not over 45' => ['600', 22],
    ]);

    it('reads where the uploads are, inside the content folder or not', function (string $uploads, string $expected): void {
        Functions\when('wp_get_upload_dir')->justReturn(['basedir' => $uploads === 'inside' ? WP_CONTENT_DIR.'/uploads' : sys_get_temp_dir(), 'baseurl' => 'https://example.com/u']);

        expect((new ReflectionMethod(ScannerFactory::class, 'uploads'))->invoke(null))->toBe($expected);
    })->with([['inside', 'uploads'], ['elsewhere', '']]);

    it('runs and keeps what it found', function (): void {
        $GLOBALS['wpdb'] = $db = new FakeWpdb;
        $disabled = [...SCAN_ALWAYS, 'exposed-files', 'security-headers'];

        $snapshot = scanner(['disabled_checks' => $disabled])?->run();

        expect(array_keys($snapshot->findings))->toBe(['database-scripts'])
            ->and($this->site->options)->toHaveKey('hornet_scan')
            ->and($db->ran)->not->toBe([]);
    });
});
