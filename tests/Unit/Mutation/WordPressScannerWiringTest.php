<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Pollora\CorePatches;
use Pollora\Hornet\Infrastructure\WordPress\ScannerFactory;
use Pollora\Hornet\Tests\Fixtures\AdminSite;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;

/*
 * What the factory gives each task: the budgets, the language of the core, the addresses it probes. None of it shows without
 * minutes of walking and a network, so it is read from the tasks: reflection is the only way in.
 */

beforeEach(function (): void {
    $this->site = FakeSite::install();
    AdminSite::stubScanner();
    $GLOBALS['wpdb'] = new FakeWpdb;
    $this->limit = (string) ini_get('max_execution_time');
});

afterEach(function (): void {
    ini_set('max_execution_time', $this->limit);
    unset($GLOBALS['wp_local_package']);
});

const MUT_WP_EVERYTHING = ['scanner' => ['probes' => true, 'probe_uploads' => true], 'vulnerabilities' => ['enabled' => true, 'composer' => true]];

/**
 * @param  array<string, mixed>  $config
 * @return array<string, ScanTask>
 */
function mutWpTasks(array $config = MUT_WP_EVERYTHING, bool $cron = false): array
{
    $scanner = ScannerFactory::forWordPress(Settings::fromArray($config), $cron);
    $tasks = [];

    foreach ((new ReflectionProperty(Scanner::class, 'tasks'))->getValue($scanner) as $task) {
        $tasks[$task->id()] = $task;
    }

    return $tasks;
}

function mutWpGiven(object $object, string $property): mixed
{
    return (new ReflectionProperty($object, $property))->getValue($object);
}

it('gives each task its share of the time of a cron run, and its own limit by hand', function (string $limit, array $cron): void {
    ini_set('max_execution_time', $limit);
    $budgets = static fn (array $tasks): array => array_map(static fn (ScanTask $task): mixed => property_exists($task, 'budget') ? mutWpGiven($task, 'budget') : 'none', $tasks);
    $without = ['core-integrity' => 'none', 'security-headers' => 'none'];

    expect(array_diff_key($budgets(mutWpTasks(cron: true)), $without))->toBe($cron)
        ->and(array_diff_key($budgets(mutWpTasks()), $without))->toBe([
            'plugin-integrity' => 240,
            'abandoned-plugins' => 240,
            'theme-integrity' => 240,
            'suspicious-files' => null,
            'database-scripts' => null,
            'exposed-files' => null,
            'vulnerabilities' => null,
            'composer-vulnerabilities' => 240,
        ]);
})->with([
    // 45 seconds: half of it is 22, a quarter 11
    'a long request' => ['0', ['plugin-integrity' => 22, 'abandoned-plugins' => 11, 'theme-integrity' => 11, 'suspicious-files' => 11, 'database-scripts' => 11, 'exposed-files' => 11, 'vulnerabilities' => 22, 'composer-vulnerabilities' => 11]],
    // 10 seconds, the least: no share goes under 5
    'a short request' => ['12', ['plugin-integrity' => 5, 'abandoned-plugins' => 5, 'theme-integrity' => 5, 'suspicious-files' => 5, 'database-scripts' => 5, 'exposed-files' => 5, 'vulnerabilities' => 5, 'composer-vulnerabilities' => 5]],
]);

it('never gives a run of the cron less than 10 seconds, nor more than 45, and keeps a margin of 8', function (string $limit, int $box): void {
    ini_set('max_execution_time', $limit);
    $scanner = ScannerFactory::forWordPress(Settings::fromArray([]), true);

    expect(mutWpGiven($scanner, 'timeBox'))->toBe($box);
})->with([
    'no limit' => ['0', 22],
    '28 seconds, 20 left' => ['28', 10],
    '29 seconds, 21 left' => ['29', 10],
    '30 seconds, 22 left' => ['30', 11],
    '1 second' => ['1', 5],
    '8 seconds, nothing left' => ['8', 5],
    '9 seconds' => ['9', 5],
    '53 seconds, 45 left' => ['53', 22],
    '54 seconds' => ['54', 22],
]);

it('compares the core with the language it was downloaded in, English when it does not say', function (mixed $locale, string $expected): void {
    if ($locale !== null) {
        $GLOBALS['wp_local_package'] = $locale;
    }

    expect(mutWpGiven(mutWpTasks()['core-integrity'], 'locale'))->toBe($expected);
})->with([['fr_FR', 'fr_FR'], [null, 'en_US'], ['', 'en_US'], [['x'], 'en_US']]);

it('lets the files differ that the owner allows and that the project patches, each once', function (): void {
    $patched = CorePatches::files();
    $allowed = mutWpGiven(mutWpTasks(['scanner' => ['allow_modified' => ['wp-load.php', 'wp-load.php', 'index.php']]])['core-integrity'], 'allowed');

    expect($allowed)->toBe(array_values(array_unique(['wp-load.php', 'index.php', ...$patched])))
        ->and($allowed)->toContain('wp-load.php', 'index.php')
        ->and(mutWpGiven(mutWpTasks()['core-integrity'], 'version'))->toBe('7.1.5');
});

it('probes the addresses of the site, without their last slash, and writes into the uploads only when asked', function (): void {
    Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://example.com/'.ltrim($path, '/'));
    $task = mutWpTasks()['exposed-files'];

    expect([mutWpGiven($task, 'site'), mutWpGiven($task, 'content'), mutWpGiven($task, 'uploads')])
        ->toBe(['https://example.com', 'https://example.com/wp-content', 'https://example.com/wp-content/uploads'])
        ->and(mutWpGiven($task, 'probeFiles'))->not->toBeNull()
        ->and(mutWpGiven($task, 'laravel'))->toBeFalse()
        ->and(mutWpGiven(mutWpTasks([])['exposed-files'], 'probeFiles'))->toBeNull();
});

it('names a probe by sixteen characters nobody can guess', function (): void {
    $token = mutWpGiven(mutWpTasks()['exposed-files'], 'token');

    expect($token())->toMatch('/\A[a-f0-9]{16}\z/')->not->toBe($token());
});

it('judges the headers of the home page, as production only on production', function (string $type, bool $production): void {
    Functions\when('wp_get_environment_type')->justReturn($type);
    $task = mutWpTasks()['security-headers'];

    expect(mutWpGiven($task, 'home'))->toBe('https://example.com/')->and(mutWpGiven($task, 'production'))->toBe($production);
})->with([['production', true], ['staging', false]]);

it('looks at the themes as themes, apart from the plugins, with a baseline of their own', function (): void {
    $tasks = mutWpTasks(['scanner' => ['own_themes' => ['mine']]]);

    expect(mutWpGiven($tasks['theme-integrity'], 'kind'))->toBe('theme')
        ->and(mutWpGiven(mutWpGiven($tasks['theme-integrity'], 'baselines'), 'option'))->toBe('hornet_baseline_themes')
        ->and(mutWpGiven(mutWpGiven($tasks['plugin-integrity'], 'baselines'), 'option'))->toBe('hornet_baseline');
});

it('gives the scan of the content folder what the owner leaves out', function (): void {
    $tasks = mutWpTasks(['scanner' => ['ignore_paths' => ['uploads/backups']]]);

    expect(mutWpGiven($tasks['suspicious-files'], 'ignored'))->toBe(['uploads/backups']);
});

it('takes for the uploads only a folder that lies inside the content folder', function (string $where, string $expected): void {
    $folder = ['inside' => WP_CONTENT_DIR.'/uploads', 'beside, with the same start' => WP_CONTENT_DIR.'-old', 'far away' => WP_CONTENT_DIR.'-old/a/long/way'][$where];
    is_dir($folder) || mkdir($folder, 0o777, true);
    Functions\when('wp_get_upload_dir')->justReturn(['basedir' => $folder, 'baseurl' => 'https://example.com/u']);

    expect(mutWpGiven(mutWpGiven(mutWpTasks()['suspicious-files'], 'inspector'), 'uploads'))->toBe($expected);
})->with([['inside', 'uploads'], ['beside, with the same start', ''], ['far away', '']]);

it('lists the tasks that are left in order, as a list', function (): void {
    $scanner = ScannerFactory::forWordPress(Settings::fromArray(['disabled_checks' => ['core-integrity'], 'scanner' => ['probes' => false]]));

    expect($scanner->tasks())->toBe(['plugin-integrity', 'abandoned-plugins', 'theme-integrity', 'suspicious-files', 'database-scripts']);
});
