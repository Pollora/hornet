<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\PluginIntegrityTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\NoPublishedChecksums;
use Pollora\Hornet\Domain\Scan\PluginIntegrity;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;
use Pollora\Hornet\Tests\Fixtures\ArrayPluginInventory;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakePluginChecksums;
use Pollora\Hornet\Tests\Fixtures\InMemoryBaselineStore;

/** @return array<string, list<string>> what WordPress.org lists for the files of a plugin folder */
function listOf(ArrayFileSystem $plugins, string $slug): array
{
    $list = [];

    foreach ($plugins->files as $path => $contents) {
        if (str_starts_with((string) $path, $slug.'/') && $contents !== null) {
            $list[substr((string) $path, strlen($slug) + 1)] = [hash('sha256', $contents)];
        }
    }

    return $list;
}

function pluginsDir(): ArrayFileSystem
{
    return new ArrayFileSystem(['akismet/akismet.php' => 'akismet', 'akismet/readme.txt' => 'readme', 'premium/premium.php' => 'premium', 'premium/lib/a.php' => 'a']);
}

/**
 * @param  array<string, string>  $versions
 */
function pluginTask(ArrayFileSystem $dir, FakePluginChecksums $official, InMemoryBaselineStore $store, array $versions = ['akismet' => '5.3', 'premium' => '2.0'], ?FakeClock $clock = null, int $budget = 240): PluginIntegrityTask
{
    return new PluginIntegrityTask(new ArrayPluginInventory($versions), $official, new PluginIntegrity($dir), $store, $clock ?? new FakeClock, $budget);
}

describe('one plugin against a list', function (): void {
    it('finds nothing when the files are the listed ones, and takes any of the accepted hashes', function (): void {
        $dir = pluginsDir();
        $list = listOf($dir, 'akismet');
        $list['readme.txt'] = [hash('sha256', 'an older readme'), hash('sha256', 'readme')];

        $verdict = (new PluginIntegrity($dir))->inspect('akismet', $list);

        expect($verdict?->isClean())->toBeTrue()->and($verdict?->checked)->toBe(2);
    });

    it('reports a file that differs, a file that is gone, one that cannot be read, and a script that is not listed', function (): void {
        $dir = pluginsDir();
        $list = listOf($dir, 'akismet') + ['gone.php' => [hash('sha256', 'x')], 'locked.php' => [hash('sha256', 'x')]];
        $dir->files['akismet/akismet.php'] = 'changed';
        $dir->files['akismet/locked.php'] = null;
        $dir->files['akismet/shell.php'] = 'x';
        $dir->files['akismet/cache/log.txt'] = 'x';
        $dir->files['akismet/node_modules/pkg/index.php'] = 'x';

        $verdict = (new PluginIntegrity($dir))->inspect('akismet', $list);

        expect($verdict?->modified)->toBe(['akismet.php'])
            ->and($verdict?->missing)->toBe(['gone.php'])
            ->and($verdict?->unreadable)->toBe(['locked.php'])
            // A log is not a script, and a tool's folder is not the plugin's
            ->and($verdict?->unexpectedCode)->toBe(['shell.php']);
    });

    it('does not know when the folder cannot be listed, and never reads a path that leaves it', function (): void {
        $dir = pluginsDir();
        $dir->unlistable = ['akismet'];

        expect((new PluginIntegrity($dir))->inspect('akismet', ['a.php' => ['x']]))->toBeNull()
            ->and((new PluginIntegrity($dir))->hashes('akismet'))->toBeNull();

        $verdict = (new PluginIntegrity(pluginsDir()))->inspect('akismet', ['../premium/premium.php' => [hash('sha256', 'premium')]]);

        expect($verdict?->checked)->toBe(0);
    });
});

describe('the scan of the plugins', function (): void {
    it('passes when each plugin matches its list, and asks for the slug and the version', function (): void {
        $dir = pluginsDir();
        $official = new FakePluginChecksums(['akismet/5.3' => listOf($dir, 'akismet')]);
        $store = new InMemoryBaselineStore;

        $result = pluginTask($dir, $official, $store, ['akismet' => '5.3'])->run(null, false);

        expect($result->finding->outcome)->toBe(Outcome::Pass)
            ->and($result->finding->summary)->toBe('1 plugin(s) match WordPress.org, 0 match what was first seen.')
            ->and($official->asked)->toBe(['akismet/5.3'])
            ->and($store->saves)->toBe(0);
    });

    it('fails at high for a changed file or a planted script, and says which', function (): void {
        $dir = pluginsDir();
        $official = new FakePluginChecksums(['akismet/5.3' => listOf($dir, 'akismet')]);
        $dir->files['akismet/akismet.php'] = 'backdoored';
        $dir->files['akismet/x.php'] = 'x';

        $finding = pluginTask($dir, $official, new InMemoryBaselineStore, ['akismet' => '5.3'])->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::High)
            ->and($finding->details)->toBe(['Changed: akismet/akismet.php', 'Script that does not belong: akismet/x.php'])
            ->and($finding->summary)->toContain('akismet');
    });

    it('fails at medium when a file is only gone', function (): void {
        $dir = pluginsDir();
        $official = new FakePluginChecksums(['akismet/5.3' => listOf($dir, 'akismet')]);
        unset($dir->files['akismet/readme.txt']);

        $finding = pluginTask($dir, $official, new InMemoryBaselineStore, ['akismet' => '5.3'])->run(null, false)->finding;

        expect($finding->severity)->toBe(Severity::Medium)->and($finding->details)->toBe(['Missing: akismet/readme.txt']);
    });

    it('keeps a first look of a plugin WordPress.org does not know, and compares with it the next time', function (): void {
        $dir = pluginsDir();
        $store = new InMemoryBaselineStore;
        $task = pluginTask($dir, new FakePluginChecksums, $store, ['premium' => '2.0']);

        $first = $task->run(null, false);
        $second = $task->run(null, false);
        $dir->files['premium/lib/a.php'] = 'changed';
        $third = $task->run(null, false);

        expect($first->finding->outcome)->toBe(Outcome::Unknown)
            ->and($first->finding->summary)->toContain('First look kept for 1 plugin(s)')
            ->and($store->baselines['premium']->version)->toBe('2.0')
            ->and(array_keys($store->baselines['premium']->hashes))->toBe(['lib/a.php', 'premium.php'])
            ->and($second->finding->outcome)->toBe(Outcome::Pass)
            ->and($second->finding->summary)->toBe('0 plugin(s) match WordPress.org, 1 match what was first seen.')
            ->and($third->finding->outcome)->toBe(Outcome::Fail)
            ->and($third->finding->details)->toBe(['Changed: premium/lib/a.php'])
            ->and($store->saves)->toBe(1);
    });

    it('takes a new version of a plugin for a new first look, and a plugin that is gone out of the baseline', function (): void {
        $dir = pluginsDir();
        $store = new InMemoryBaselineStore(['premium' => new Baseline('1.0', ['premium.php' => hash('sha256', 'old')]), 'removed' => new Baseline('1.0', [])]);

        $result = pluginTask($dir, new FakePluginChecksums, $store, ['premium' => '2.0'])->run(null, false);

        expect($result->finding->outcome)->toBe(Outcome::Unknown)
            ->and(array_keys($store->baselines))->toBe(['premium'])
            ->and($store->baselines['premium']->version)->toBe('2.0');
    });

    it('looks again at the plugins as they are now when asked to pin again', function (): void {
        $dir = pluginsDir();
        $store = new InMemoryBaselineStore;
        $task = pluginTask($dir, new FakePluginChecksums, $store, ['premium' => '2.0']);
        $task->run(null, false);
        $dir->files['premium/lib/a.php'] = 'reinstalled, on purpose';

        $result = $task->run(null, true);

        expect($result->finding->outcome)->toBe(Outcome::Unknown)
            ->and($store->baselines['premium']->hashes['lib/a.php'])->toBe(hash('sha256', 'reinstalled, on purpose'));
    });

    it('says what it could not check, and does not call that a pass', function (): void {
        $dir = pluginsDir();
        $official = new FakePluginChecksums(['akismet/5.3' => new TransportException('down')]);

        $down = pluginTask($dir, $official, new InMemoryBaselineStore, ['akismet' => '5.3'])->run(null, false)->finding;

        $dir->unlistable = ['premium'];
        $locked = pluginTask($dir, new FakePluginChecksums, new InMemoryBaselineStore, ['premium' => '2.0'])->run(null, false)->finding;

        expect($down->outcome)->toBe(Outcome::Unknown)->and($down->summary)->toContain('akismet (WordPress.org did not answer)')
            ->and($locked->summary)->toContain('premium (folder cannot be read)');
    });

    it('passes the plugins it could compare and names the others', function (): void {
        $dir = pluginsDir();
        $official = new FakePluginChecksums(['akismet/5.3' => listOf($dir, 'akismet'), 'premium/2.0' => new TransportException('down')]);

        $finding = pluginTask($dir, $official, new InMemoryBaselineStore)->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Pass)->and($finding->details)->toBe(['Not checked: premium (WordPress.org did not answer)']);
    });

    it('stops asking when the time is up, and names the plugins left', function (): void {
        $dir = pluginsDir();
        $clock = new FakeClock;
        // Each answer takes ten minutes
        $official = new FakePluginChecksums(['akismet/5.3' => listOf($dir, 'akismet')], $clock, 'PT10M');

        $finding = pluginTask($dir, $official, new InMemoryBaselineStore, clock: $clock, budget: 60)->run(null, false)->finding;

        expect($official->asked)->toBe(['akismet/5.3'])
            ->and($finding->details)->toBe(['Not checked: premium (out of time)']);
    });

    it('does not keep a first look of a plugin too large for one', function (): void {
        $files = [];

        foreach (range(1, 5001) as $number) {
            $files['big/f'.$number.'.txt'] = 'x'.$number;
        }

        $finding = pluginTask(new ArrayFileSystem($files), new FakePluginChecksums, new InMemoryBaselineStore, ['big' => '1.0'])->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Unknown)->and($finding->summary)->toContain('too many files');
    });

    it('passes when no plugin has a folder of its own', function (): void {
        expect(pluginTask(pluginsDir(), new FakePluginChecksums, new InMemoryBaselineStore, [])->run(null, false)->finding->outcome)->toBe(Outcome::Pass);
    });

    it('lists the first findings and counts the rest', function (): void {
        $dir = new ArrayFileSystem(['p/a.php' => 'a']);
        $official = new FakePluginChecksums(['p/1.0' => ['a.php' => [hash('sha256', 'a')]]]);

        foreach (range(1, 20) as $number) {
            $dir->files['p/shell'.$number.'.php'] = 'x';
        }

        $finding = pluginTask($dir, $official, new InMemoryBaselineStore, ['p' => '1.0'])->run(null, false)->finding;

        expect($finding->details)->toHaveCount(16)->and($finding->details[15])->toBe('Script that does not belong: and 5 more');
    });

    it('says in the summary of a pass how many plugins were first seen now, so that it is not read as a plain pass', function (): void {
        $dir = pluginsDir();
        $official = new FakePluginChecksums(['akismet/5.3' => listOf($dir, 'akismet')]);

        $finding = pluginTask($dir, $official, new InMemoryBaselineStore)->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Pass)
            ->and($finding->summary)->toBe('1 plugin(s) match WordPress.org, 0 match what was first seen, 1 first seen now.')
            ->and($finding->details[0])->toContain('First look kept for 1 plugin(s)');
    });
});

describe('the themes, which WordPress.org publishes no list for', function (): void {
    function themeTask(ArrayFileSystem $dir, InMemoryBaselineStore $store): PluginIntegrityTask
    {
        return new PluginIntegrityTask(new ArrayPluginInventory(['mytheme' => '1.0']), new NoPublishedChecksums, new PluginIntegrity($dir), $store, new FakeClock, 240, 'theme-integrity', 'theme');
    }

    it('keeps a first look, then compares with it, and says theme', function (): void {
        $dir = new ArrayFileSystem(['mytheme/style.css' => 'css', 'mytheme/functions.php' => '<?php // f']);
        $store = new InMemoryBaselineStore;
        $task = themeTask($dir, $store);

        $first = $task->run(null, false)->finding;
        $second = $task->run(null, false)->finding;

        expect($first->checkId)->toBe('theme-integrity')->and($first->outcome)->toBe(Outcome::Unknown)
            ->and($first->summary)->toContain('First look kept for 1 theme(s)')
            ->and($second->outcome)->toBe(Outcome::Pass)
            ->and($second->summary)->toBe('1 theme(s) match what was first seen.');
    });

    it('reports a changed file and a planted script in a theme', function (): void {
        $dir = new ArrayFileSystem(['mytheme/style.css' => 'css', 'mytheme/functions.php' => '<?php // f']);
        $store = new InMemoryBaselineStore;
        themeTask($dir, $store)->run(null, false);
        $dir->files['mytheme/functions.php'] = '<?php // changed';
        $dir->files['mytheme/inc/x.php'] = '<?php // planted';

        $finding = themeTask($dir, $store)->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::High)
            ->and($finding->summary)->toBe('1 theme has changed since it was published or first seen: mytheme.')
            ->and($finding->details)->toBe(['Changed: mytheme/functions.php', 'Script that does not belong: mytheme/inc/x.php'])
            ->and($finding->fix)->toContain('reinstall the theme');
    });

    it('says in its own words that there is no theme', function (): void {
        $task = new PluginIntegrityTask(new ArrayPluginInventory([]), new NoPublishedChecksums, new PluginIntegrity(new ArrayFileSystem), new InMemoryBaselineStore, new FakeClock, 240, 'theme-integrity', 'theme');

        expect($task->run(null, false)->finding->summary)->toBe('No theme has a folder of its own.');
    });

    it('has no list for any theme', function (): void {
        expect((new NoPublishedChecksums)->checksums('twentytwentysix', '1.0'))->toBeNull();
    });
});
