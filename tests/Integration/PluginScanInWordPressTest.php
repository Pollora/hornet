<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\PluginIntegrityTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\PluginIntegrity;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Scan\NativeFileSystem;
use Pollora\Hornet\Infrastructure\WordPress\OptionBaselineStore;
use Pollora\Hornet\Infrastructure\WordPress\WpPluginInventory;
use Pollora\Hornet\Tests\Fixtures\FakePluginChecksums;

function pluginsOnDisk(): NativeFileSystem
{
    $plugins = NativeFileSystem::at(WP_PLUGIN_DIR);
    assert($plugins instanceof NativeFileSystem);

    return $plugins;
}

/**
 * What WordPress.org would say about the plugins if they were untouched: the hash of each file as it is now.
 *
 * @return array<string, array<string, list<string>>>
 */
function listsFromDisk(): array
{
    $lists = [];

    foreach ((new WpPluginInventory)->plugins() as $plugin) {
        foreach (pluginsOnDisk()->files($plugin->slug, true) ?? [] as $file) {
            $lists[$plugin->slug.'/'.$plugin->version][substr($file, strlen($plugin->slug) + 1)] = [(string) pluginsOnDisk()->sha256($file)];
        }
    }

    return $lists;
}

beforeEach(function (): void {
    delete_option(OptionBaselineStore::OPTION);
});

afterEach(function (): void {
    delete_option(OptionBaselineStore::OPTION);
});

it('lists the plugins that have a folder of their own, with their version', function (): void {
    $slugs = array_map(static fn ($plugin): string => $plugin->slug, (new WpPluginInventory)->plugins());

    expect($slugs)->toContain('two-factor')->and($slugs)->not->toContain('.');
});

it('keeps the first look of the plugins in an option that is not loaded on every page', function (): void {
    $store = new OptionBaselineStore;

    expect($store->load())->toBe([]);

    $store->save(['premium' => new Baseline('2.0', ['a.php' => 'hash'])]);

    expect($store->load()['premium']->hashes)->toBe(['a.php' => 'hash'])
        ->and(array_key_exists(OptionBaselineStore::OPTION, wp_load_alloptions(true)))->toBeFalse();

    foreach (['', 'not json', '[]', '{"premium":"text"}', '123'] as $stored) {
        update_option(OptionBaselineStore::OPTION, $stored);

        expect((new OptionBaselineStore)->load())->toBe([]);
    }
});

it('finds nothing in the real plugins when they are what the list says, and a script planted in one', function (): void {
    $task = fn (): PluginIntegrityTask => new PluginIntegrityTask(new WpPluginInventory, new FakePluginChecksums(listsFromDisk()), new PluginIntegrity(pluginsOnDisk()), new OptionBaselineStore, new SystemClock);
    $lists = listsFromDisk();
    $clean = (new PluginIntegrityTask(new WpPluginInventory, new FakePluginChecksums($lists), new PluginIntegrity(pluginsOnDisk()), new OptionBaselineStore, new SystemClock))->run(null, false)->finding;

    $planted = WP_PLUGIN_DIR.'/two-factor/hornet-integration-shell.php';
    file_put_contents($planted, '<?php // planted by a test');

    try {
        $found = (new PluginIntegrityTask(new WpPluginInventory, new FakePluginChecksums($lists), new PluginIntegrity(pluginsOnDisk()), new OptionBaselineStore, new SystemClock))->run(null, false)->finding;
    } finally {
        unlink($planted);
    }

    expect($task())->toBeInstanceOf(PluginIntegrityTask::class)
        ->and($clean->outcome)->toBe(Outcome::Pass)
        ->and($found->outcome)->toBe(Outcome::Fail)
        ->and($found->details)->toBe(['Script that does not belong: two-factor/hornet-integration-shell.php']);
});

it('keeps a first look of a plugin that WordPress.org does not know, then sees it change', function (): void {
    $folder = WP_PLUGIN_DIR.'/hornet-integration-premium';
    mkdir($folder, 0o777, true);
    file_put_contents($folder.'/premium.php', "<?php\n/*\nPlugin Name: Hornet integration premium\nVersion: 2.0\n*/\n");

    $task = fn (): PluginIntegrityTask => new PluginIntegrityTask(new WpPluginInventory, new FakePluginChecksums, new PluginIntegrity(pluginsOnDisk()), new OptionBaselineStore, new SystemClock);

    try {
        wp_cache_delete('plugins', 'plugins');
        $first = $task()->run(null, false)->finding;
        $second = $task()->run(null, false)->finding;
        file_put_contents($folder.'/premium.php', "<?php\n/*\nPlugin Name: Hornet integration premium\nVersion: 2.0\n*/\n// changed\n");
        $third = $task()->run(null, false)->finding;
        $kept = (new OptionBaselineStore)->load();
    } finally {
        unlink($folder.'/premium.php');
        rmdir($folder);
        wp_cache_delete('plugins', 'plugins');
    }

    expect($first->summary)->toContain('First look kept')
        ->and($kept['hornet-integration-premium']->version)->toBe('2.0')
        ->and($second->details)->toBe([])
        ->and($third->outcome)->toBe(Outcome::Fail)
        ->and($third->details)->toBe(['Changed: hornet-integration-premium/premium.php']);
});
