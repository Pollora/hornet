<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\AbandonedPluginsTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Scan\CachedPluginDirectory;
use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\PluginDirectory;
use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Domain\Scan\PluginListingStatus;
use Pollora\Hornet\Infrastructure\WordPress\WpPluginDirectory;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\InMemoryPluginListingCache;
use Pollora\Hornet\Tests\Fixtures\ListedPlugins;
use Pollora\Hornet\Tests\Fixtures\ScriptedPluginDirectory;

function plugin(string $slug, string $name, string $version = '1.0', string $updateUri = ''): InstalledPlugin
{
    return new InstalledPlugin($slug, $version, $name, $updateUri);
}

function listedAt(string $name, string $day, string $version = '1.0'): PluginListing
{
    return PluginListing::listed($name, $version, (int) strtotime($day.' UTC'));
}

/**
 * @param  list<InstalledPlugin>  $plugins
 */
function abandoned(PluginDirectory $directory, array $plugins, ?FakeClock $clock = null, ?int $budget = null, ?Closure $fetched = null): TaskResult
{
    return (new AbandonedPluginsTask($directory, new ListedPlugins($plugins), $clock ?? new FakeClock, $fetched ?? static fn (): int => 0, $budget))->run(null, false);
}

it('reports a closed plugin as high, with the day and the reason', function (): void {
    $directory = new ScriptedPluginDirectory(['display-widgets' => PluginListing::closed('Display Widgets', '2021-01-30', 'security-issue')]);

    $finding = abandoned($directory, [plugin('display-widgets', 'Display Widgets', '2.07')])->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::High)
        ->and($finding->summary)->toBe('1 plugin has been closed by WordPress.org: display-widgets.')
        ->and($finding->details)->toBe(['display-widgets: closed by WordPress.org on 2021-01-30 (security issue)'])
        ->and($finding->fix)->toContain('Hornet changes nothing');
});

it('says each closing reason in its own words, and repeats none that it does not know', function (?string $reason, string $said): void {
    $directory = new ScriptedPluginDirectory(['gone' => PluginListing::closed('Gone', null, $reason)]);

    expect(abandoned($directory, [plugin('gone', 'Gone')])->finding->details)->toBe(['gone: closed by WordPress.org ('.$said.')']);
})->with([
    ['author-request', 'at the request of its author'],
    ['guideline-violation', 'it broke the rules of the directory'],
    ['licensing-trademark-violation', 'licence or trademark violation'],
    ['merged-into-core', 'now part of WordPress'],
    ['unused', 'not used'],
    'held back for sixty days' => [null, 'reason not published'],
    'not one of the list' => ['<script>', 'reason not published'],
]);

it('reports two years without a release as low, and not a day less', function (): void {
    // The clock says 2026-11-25
    $directory = new ScriptedPluginDirectory(['old' => listedAt('Old', '2024-11-25'), 'recent' => listedAt('Recent', '2024-11-26')]);

    $finding = abandoned($directory, [plugin('old', 'Old'), plugin('recent', 'Recent')])->finding;

    expect($finding->severity)->toBe(Severity::Low)
        ->and($finding->summary)->toBe('1 plugin has had no release on WordPress.org for two years or more: old.')
        ->and($finding->details)->toBe(['old: no release on WordPress.org since 2024-11-25']);
});

it('says both, and the closed one decides how bad it is', function (): void {
    $directory = new ScriptedPluginDirectory(['gone' => PluginListing::closed('Gone', '2025-01-01', null), 'old' => listedAt('Old', '2020-01-01')]);

    $finding = abandoned($directory, [plugin('old', 'Old'), plugin('gone', 'Gone')])->finding;

    expect($finding->severity)->toBe(Severity::High)
        ->and($finding->summary)->toBe('1 plugin has been closed by WordPress.org: gone. 1 plugin has had no release on WordPress.org for two years or more: old.')
        ->and($finding->details[0])->toStartWith('gone:');
});

it('passes when what is on WordPress.org is open and recent, and counts only that', function (): void {
    $directory = new ScriptedPluginDirectory(['akismet' => listedAt('Akismet Anti-spam: Spam Protection', '2026-08-18', '5.7.2')]);

    $result = abandoned($directory, [plugin('akismet', 'Akismet Anti-spam: Spam Protection', '5.7'), plugin('my-own', 'My own plugin')]);

    expect($result->finding->outcome)->toBe(Outcome::Pass)
        ->and($result->finding->summary)->toBe('The 1 plugin recognised on WordPress.org is open and has had a release in the last two years.')
        ->and($result->unfinished)->toBeFalse()
        ->and($directory->asked)->toBe(['akismet', 'my-own']);
});

it('does not take a premium or home-made plugin for the one that has its folder name', function (): void {
    $directory = new ScriptedPluginDirectory([
        'forms' => PluginListing::closed('Easy Contact Forms', '2022-01-01', 'security-issue'),
        // The installed one is a later release than anything the directory ever had
        'slider' => listedAt('Slider', '2019-01-01', '1.4'),
    ]);

    $finding = abandoned($directory, [plugin('forms', 'Acme Forms Pro'), plugin('slider', 'Slider', '6.2')])->finding;

    expect($finding->outcome)->toBe(Outcome::Pass)
        ->and($finding->summary)->toBe('No plugin of this site was recognised on WordPress.org: nothing to say about closed or old plugins.')
        ->and($finding->details)->toBe(['Same folder name as a plugin that is closed or old on WordPress.org, under another name or version, so probably another plugin and not counted: forms, slider']);
});

it('does not ask about a plugin that says it is updated from somewhere else', function (string $uri, bool $asked): void {
    $directory = new ScriptedPluginDirectory;

    $finding = abandoned($directory, [plugin('thing', 'Thing', '1.0', $uri)])->finding;

    expect($directory->asked)->toBe($asked ? ['thing'] : [])
        ->and($finding->details)->toBe($asked ? [] : ['1 plugin says it is updated from somewhere else and was not asked about.']);
})->with([
    'no header' => ['', true],
    'the directory' => ['https://wordpress.org/plugins/thing/', true],
    'w.org without a scheme' => ['w.org/plugin/thing', true],
    'a vendor' => ['https://example.com/updates', false],
    'no updates at all' => ['false', false],
    'a look-alike host' => ['https://wordpress.org.example.com/x', false],
]);

it('is not a pass when a plugin could not be asked about', function (): void {
    $directory = new ScriptedPluginDirectory(['akismet' => listedAt('Akismet', '2026-01-01'), 'other' => new TransportException('down')]);

    $result = abandoned($directory, [plugin('akismet', 'Akismet'), plugin('other', 'Other')]);

    expect($result->finding->outcome)->toBe(Outcome::Unknown)->and($result->finding->summary)->toBe('WordPress.org could not be asked about: other')->and($result->unfinished)->toBeFalse();
});

it('is not a pass either when the date of the last release cannot be read', function (): void {
    $directory = new ScriptedPluginDirectory(['odd' => PluginListing::listed('Odd', '1.0', null)]);

    expect(abandoned($directory, [plugin('odd', 'Odd')])->finding->outcome)->toBe(Outcome::Unknown);
});

it('keeps a closed plugin in the verdict when others could not be asked about', function (): void {
    $directory = new ScriptedPluginDirectory(['gone' => PluginListing::closed('Gone', '2025-01-01', null), 'other' => new TransportException('down')]);

    $finding = abandoned($directory, [plugin('gone', 'Gone'), plugin('other', 'Other')])->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->details)->toContain('WordPress.org could not be asked about: other');
});

it('stops when the time is up, goes on at the next run, and ends', function (): void {
    $clock = new FakeClock;
    $slugs = ['a', 'b', 'c', 'd', 'e'];
    $inner = new ScriptedPluginDirectory(onAsk: static fn () => $clock->advance('PT10S'));
    $plugins = array_map(static fn (string $slug): InstalledPlugin => plugin($slug, $slug), $slugs);
    $cache = new InMemoryPluginListingCache;
    $run = static function () use ($inner, $cache, $clock, $plugins): TaskResult {
        $directory = new CachedPluginDirectory($inner, $cache, $clock);

        return abandoned($directory, $plugins, $clock, 15, $directory->fetched(...));
    };

    $first = $run();

    expect($first->unfinished)->toBeTrue()->and($first->finding->outcome)->toBe(Outcome::Unknown)
        ->and($first->finding->summary)->toBe('3 plugins were not reached in the time allowed.')
        ->and($inner->asked)->toBe(['a', 'b']);

    $second = $run();
    $third = $run();

    // What was answered is not asked again
    expect($second->unfinished)->toBeTrue()->and($third->unfinished)->toBeFalse()
        ->and($third->finding->outcome)->toBe(Outcome::Pass)->and($inner->asked)->toBe($slugs);
});

it('does not ask to go on when a run got no answer at all', function (): void {
    $clock = new FakeClock;
    $inner = new ScriptedPluginDirectory(down: true, onAsk: static fn () => $clock->advance('PT10S'));
    $directory = new CachedPluginDirectory($inner, new InMemoryPluginListingCache, $clock);

    $result = abandoned($directory, [plugin('a', 'A'), plugin('b', 'B'), plugin('c', 'C')], $clock, 5, $directory->fetched(...));

    expect($result->unfinished)->toBeFalse()->and($result->finding->outcome)->toBe(Outcome::Unknown);
});

it('keeps an answer a day, a closed one a week, and never a failure', function (): void {
    $clock = new FakeClock;
    $inner = new ScriptedPluginDirectory(['gone' => PluginListing::closed('Gone', null, null), 'down' => new TransportException('down')]);
    $cache = new InMemoryPluginListingCache;
    $directory = new CachedPluginDirectory($inner, $cache, $clock);

    $directory->of('gone');
    $directory->of('here');
    $clock->advance('P2D');
    $directory->of('gone');
    $directory->of('here');

    expect($inner->asked)->toBe(['gone', 'here', 'here']);

    $clock->advance('P6D');
    $directory->of('gone');

    expect($inner->asked)->toHaveCount(4)->and(fn () => $directory->of('down'))->toThrow(TransportException::class)->and($cache->stored)->not->toHaveKey('down');
});

it('recognises the name of a plugin with or without its slogan', function (string $installed, string $listed, bool $same): void {
    expect(listedAt($listed, '2026-01-01')->isSamePlugin(plugin('x', $installed)))->toBe($same);
})->with([
    'same' => ['Display Widgets', 'Display Widgets', true],
    'slogan on the directory' => ['Yoast SEO', 'Yoast SEO &#8211; Advanced SEO with real-time guidance', true],
    'by someone' => ['WP Mail SMTP', 'WP Mail SMTP by WPForms – The Most Popular SMTP', true],
    'case and punctuation' => ['classic editor', 'Classic Editor', true],
    'one common word' => ['Slider', 'Slider Revolution Free', false],
    'another plugin' => ['Acme Forms', 'Easy Forms', false],
    'no name' => ['', 'Display Widgets', false],
]);

it('reads the three shapes api.wordpress.org answers with', function (): void {
    $http = new FakeHttpClient;
    $http->answers = [
        // Read on 2026-11-26: a closed plugin is a 404
        new HttpResponse(404, null, '{"error":"closed","name":"Display Widgets","slug":"display-widgets","description":"This plugin has been closed as of January 30, 2021 and is not available for download. This closure is permanent. Reason: Security Issue.","closed":true,"closed_date":"2021-01-30","reason":"security-issue","reason_text":"Security Issue"}'),
        new HttpResponse(404, null, '{"error":"Plugin not found."}'),
        new HttpResponse(200, null, '{"name":"Akismet Anti-spam: Spam Protection","slug":"akismet","version":"5.7.2","last_updated":"2026-08-18 11:42pm GMT","added":"2005-10-20"}'),
        new HttpResponse(404, null, '{"error":"closed","name":"New","slug":"new","closed":true,"closed_date":false,"reason":false,"reason_text":false}'),
        new HttpResponse(200, null, '{"name":"Odd","slug":"odd","version":"1","last_updated":"yesterday"}'),
    ];
    $directory = new WpPluginDirectory($http);

    $closed = $directory->of('display-widgets');
    $absent = $directory->of('nothing-here');
    $listed = $directory->of('akismet');
    $held = $directory->of('new');

    expect([$closed->status, $closed->closedOn, $closed->reason])->toBe([PluginListingStatus::Closed, '2021-01-30', 'security-issue'])
        ->and($absent->status)->toBe(PluginListingStatus::Absent)
        ->and([$listed->status, $listed->version, gmdate('Y-m-d', (int) $listed->updatedAt)])->toBe([PluginListingStatus::Listed, '5.7.2', '2026-08-18'])
        ->and([$held->closedOn, $held->reason])->toBe([null, null])
        ->and($directory->of('odd')->updatedAt)->toBeNull()
        ->and($http->gets[0]['url'])->toStartWith('https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=display-widgets&request%5Bfields%5D%5Bsections%5D=0');
});

it('takes anything else for no answer, never for "not on WordPress.org"', function (int $status, string $body): void {
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse($status, null, $body)];

    expect(fn () => (new WpPluginDirectory($http))->of('akismet'))->toThrow(TransportException::class);
})->with([
    'a server error' => [500, ''],
    'too many requests' => [429, '{"error":"slow down"}'],
    'a 404 that is something else' => [404, '<html>Not found</html>'],
    'another 404' => [404, '{"code":"rest_no_route"}'],
    'an error with 200' => [200, '{"error":"Plugin not found."}'],
    'another plugin' => [200, '{"name":"Other","slug":"other"}'],
    'cut' => [200, '{"name":"Akis'],
]);

it('never puts a folder name that is not a plain one in an address', function (): void {
    $http = new FakeHttpClient;

    expect((new WpPluginDirectory($http))->of('a b&c')->status)->toBe(PluginListingStatus::Absent)->and($http->gets)->toBe([]);
});

it('reads back what it stores, and nothing else', function (): void {
    $listing = PluginListing::closed('Gone', '2025-01-01', 'unused');

    expect(PluginListing::fromArray($listing->toArray()))->toEqual($listing)
        ->and(PluginListing::fromArray(['status' => 'what', 'name' => 'x']))->toBeNull()
        ->and(PluginListing::fromArray('x'))->toBeNull();
});
