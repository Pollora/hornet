<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\CoreChecksums;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Scan\NativeFileSystem;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\ScannerFactory;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;

/**
 * What WordPress.org would say about this install if the install were untouched: the hash of each core file as it is now.
 * The real answer is asked in tests/E2E (it needs the network and a release that WordPress.org knows).
 *
 * @return array<string, string>
 */
function officialFromDisk(): array
{
    $files = NativeFileSystem::at(ABSPATH);
    $list = [];

    foreach ([...($files?->files('', false) ?? []), ...($files?->files('wp-admin', true) ?? []), ...($files?->files('wp-includes', true) ?? [])] as $path) {
        // WordPress.org lists no wp-config.php
        if ($path !== 'wp-config.php') {
            $list[$path] = (string) $files?->md5($path);
        }
    }

    return $list;
}

/**
 * @param  array<string, string>  $official  taken before the test changes the disk
 * @param  list<string>  $allowed
 */
function realTask(array $official, array $allowed = []): CoreIntegrityTask
{
    $files = NativeFileSystem::at(ABSPATH);
    assert($files instanceof NativeFileSystem);

    return new CoreIntegrityTask(new class($official) implements CoreChecksums
    {
        /** @param array<string, string> $list */
        public function __construct(private readonly array $list) {}

        public function checksums(string $version, string $locale): array
        {
            return $this->list;
        }
    }, $files, wp_get_wp_version(), 'en_US', $allowed);
}

function realScanner(): Scanner
{
    return new Scanner([realTask(officialFromDisk())], new OptionScanStore, new SystemClock);
}

beforeEach(function (): void {
    delete_option(OptionScanStore::OPTION);
});

afterEach(function (): void {
    delete_option(OptionScanStore::OPTION);
});

it('keeps the last scan in an option that WordPress does not load on every page', function (): void {
    $store = new OptionScanStore;

    expect($store->last())->toBeNull();

    $snapshot = realScanner()->run();

    // The time is kept to the second
    expect($store->last()?->toArray())->toBe($snapshot->toArray())
        ->and(array_key_exists(OptionScanStore::OPTION, wp_load_alloptions(true)))->toBeFalse()
        ->and(get_option(OptionScanStore::OPTION))->toBeString();
});

it('does not read what is not a scan, and does not break on it', function (): void {
    foreach (['', 'not json', '{"at":"x"}', '[]', '123'] as $stored) {
        update_option(OptionScanStore::OPTION, $stored);

        expect((new OptionScanStore)->last())->toBeNull();
    }

    update_option(OptionScanStore::OPTION, ['not' => 'a string']);

    expect((new OptionScanStore)->last())->toBeNull();
});

it('scans the real core files and finds nothing in an untouched install', function (): void {
    $snapshot = realScanner()->run();
    $finding = $snapshot->finding('core-integrity');

    expect($finding?->outcome)->toBe(Outcome::Pass)
        ->and($finding?->summary)->toContain('core files match WordPress');
});

it('finds a script planted in wp-includes, and says it is high', function (): void {
    $planted = ABSPATH.'wp-includes/hornet-integration-shell.php';
    $official = officialFromDisk();
    file_put_contents($planted, '<?php // planted by a test');

    try {
        $finding = (new Scanner([realTask($official)], new OptionScanStore, new SystemClock))->run()->finding('core-integrity');
    } finally {
        unlink($planted);
    }

    expect($finding?->outcome)->toBe(Outcome::Fail)
        ->and($finding?->severity)->toBe(Severity::High)
        ->and($finding?->details)->toBe(['Not part of WordPress: wp-includes/hornet-integration-shell.php']);
});

it('finds a core file that was changed, then pins an allowed one and sees it change again', function (): void {
    $file = ABSPATH.'wp-includes/version.php';
    $original = (string) file_get_contents($file);
    // The list is taken from the untouched file: the change comes after
    $official = officialFromDisk();

    try {
        file_put_contents($file, $original."\n// patched");
        $changed = realTask($official)->run(null, false)->finding;
        $first = realTask($official, ['wp-includes/version.php'])->run(null, false);

        file_put_contents($file, $original."\n// patched, and then backdoored");
        $second = realTask($official, ['wp-includes/version.php'])->run($first->pins, false);
    } finally {
        file_put_contents($file, $original);
    }

    expect($changed->outcome)->toBe(Outcome::Fail)->and($changed->details)->toBe(['Changed: wp-includes/version.php'])
        ->and($first->finding->outcome)->toBe(Outcome::Pass)
        ->and($first->pins)->toBeInstanceOf(Pins::class)
        ->and($second->finding->outcome)->toBe(Outcome::Fail)
        ->and($second->finding->details)->toBe(['Changed since first seen: wp-includes/version.php']);
});

it('shows the audit what the scan found, and says it does not know before the first scan', function (): void {
    $environment = new WordPressEnvironment;
    $auditor = new Auditor(DefaultChecks::all());

    $before = $auditor->run($environment)->find('core-integrity');

    realScanner()->run();
    $after = $auditor->run($environment)->find('core-integrity');

    expect($before?->outcome)->toBe(Outcome::Unknown)
        ->and($after?->outcome)->toBe(Outcome::Pass);
});

it('builds the scanner of the site from the settings, without asking the network', function (): void {
    $scanner = ScannerFactory::forWordPress(Settings::fromArray(['scanner' => ['allow_modified' => ['wp-includes/l10n.php']]]));

    expect($scanner)->toBeInstanceOf(Scanner::class)
        ->and($scanner?->tasks())->toBe(['core-integrity', 'plugin-integrity', 'suspicious-files']);
});
