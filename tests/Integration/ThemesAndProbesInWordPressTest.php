<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\ExposedFilesTask;
use Pollora\Hornet\Application\Scan\PluginIntegrityTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Scan\NoPublishedChecksums;
use Pollora\Hornet\Domain\Scan\PluginIntegrity;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Scan\NativeFileSystem;
use Pollora\Hornet\Infrastructure\Scan\UploadsProbeFiles;
use Pollora\Hornet\Infrastructure\WordPress\OptionBaselineStore;
use Pollora\Hornet\Infrastructure\WordPress\WpThemeInventory;
use Pollora\Hornet\Tests\Fixtures\RoutedHttpClient;

afterEach(function (): void {
    delete_option(OptionBaselineStore::THEMES);
    delete_option(OptionBaselineStore::OPTION);
});

it('lists the themes of the real install, and leaves out the ones that are the owner\'s', function (): void {
    $all = array_map(static fn ($theme): string => $theme->slug, (new WpThemeInventory)->plugins());
    $own = array_map(static fn ($theme): string => $theme->slug, (new WpThemeInventory([$all[0]]))->plugins());

    expect($all)->not->toBe([])->and($own)->not->toContain($all[0])->and(count($own))->toBe(count($all) - 1)
        ->and((new WpThemeInventory)->plugins()[0]->version)->not->toBe('');
});

it('keeps the first look of the themes in an option of their own, apart from the plugins\'', function (): void {
    $themes = NativeFileSystem::at(get_theme_root());
    assert($themes instanceof NativeFileSystem);
    $task = fn (): PluginIntegrityTask => new PluginIntegrityTask(new WpThemeInventory, new NoPublishedChecksums, new PluginIntegrity($themes), new OptionBaselineStore(OptionBaselineStore::THEMES), new SystemClock, 240, 'theme-integrity', 'theme');

    $first = $task()->run(null, false)->finding;
    $second = $task()->run(null, false)->finding;
    $folder = get_theme_root().'/'.(new WpThemeInventory)->plugins()[0]->slug;
    file_put_contents($folder.'/hornet-it-shell.php', '<?php // planted by a test');

    try {
        $third = $task()->run(null, false)->finding;
    } finally {
        unlink($folder.'/hornet-it-shell.php');
    }

    expect($first->outcome)->toBe(Outcome::Unknown)->and($second->outcome)->toBe(Outcome::Pass)
        ->and($third->outcome)->toBe(Outcome::Fail)
        ->and($third->details[0])->toEndWith('/hornet-it-shell.php')
        ->and(get_option(OptionBaselineStore::OPTION))->toBeFalse()
        ->and(array_key_exists(OptionBaselineStore::THEMES, wp_load_alloptions(true)))->toBeFalse();
});

it('asks the site for what it should not give away, and says what it found without repeating it', function (): void {
    $site = untrailingslashit(home_url());
    $http = new RoutedHttpClient([$site.'/.env' => new HttpResponse(200, null, "DB_PASSWORD=hunter2\n")]);

    $finding = (new ExposedFilesTask($http, $site, untrailingslashit(content_url()), untrailingslashit(wp_get_upload_dir()['baseurl']), null, new SystemClock, null, static fn (): string => '0123456789abcdef'))->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->details[0])->toStartWith('[high] /.env')
        ->and(json_encode($finding->toArray()))->not->toContain('hunter2');
});

it('writes its probe file in the real uploads, and removes it, and sweeps what a dead run left', function (): void {
    $uploads = wp_get_upload_dir()['basedir'];
    wp_mkdir_p($uploads);
    $files = new UploadsProbeFiles($uploads);
    $stale = $uploads.'/hornet-probe-aaaaaaaaaaaaaaaa.php';
    file_put_contents($stale, "<?php echo strrev('abc');\n");

    $planted = $files->plant('hornet-probe-0123456789abcdef.php', "<?php echo strrev('x');\n");
    $seen = file_exists($uploads.'/hornet-probe-0123456789abcdef.php');
    $swept = ! file_exists($stale);
    $files->remove('hornet-probe-0123456789abcdef.php');

    expect($planted)->toBeTrue()->and($seen)->toBeTrue()->and($swept)->toBeTrue()
        ->and(file_exists($uploads.'/hornet-probe-0123456789abcdef.php'))->toBeFalse();
});
