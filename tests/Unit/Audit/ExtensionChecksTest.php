<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Checks\InactiveExtensionsCheck;
use Pollora\Hornet\Domain\Audit\Checks\UpdatesCheck;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;

function waiting(int $count): array
{
    return array_map(static fn (int $n): array => ['slug' => 'one-'.$n, 'installed' => '1.0', 'available' => '1.1'], range(1, $count));
}

it('says not verified, and not that all is well, when WordPress did not look lately', function (UpdatesCheck $check, string $property): void {
    $now = (new FakeEnvironment)->now()->getTimestamp();

    expect($check->run(new FakeEnvironment(...[$property => null]))->outcome)->toBe(Outcome::Unknown)
        ->and($check->run(new FakeEnvironment(...[$property => ['checked' => $now - 8 * 86400, 'waiting' => [], 'unfollowed' => 0]]))->outcome)->toBe(Outcome::Unknown)
        ->and($check->run(new FakeEnvironment(...[$property => ['checked' => $now - 6 * 86400, 'waiting' => [], 'unfollowed' => 0]]))->outcome)->toBe(Outcome::Pass);
})->with([
    'plugins' => [UpdatesCheck::plugins(), 'pluginUpdates'],
    'themes' => [UpdatesCheck::themes(), 'themeUpdates'],
]);

it('lists the updates that wait, and says how many it cannot follow', function (UpdatesCheck $check, string $property, string $word): void {
    $now = (new FakeEnvironment)->now()->getTimestamp();
    $finding = $check->run(new FakeEnvironment(...[$property => ['checked' => $now, 'waiting' => waiting(7), 'unfollowed' => 2]]));

    expect($finding->severity)->toBe(Severity::Medium)
        ->and($finding->summary)->toBe('7 '.$word.'s have an update waiting.')
        ->and($finding->details)->toHaveCount(7)
        ->and($finding->details[0])->toBe('one-1: 1.0 is installed, 1.1 is available.')
        ->and($finding->details[5])->toBe('and 2 more')
        ->and($finding->details[6])->toStartWith('2 '.$word.'s are not followed')
        ->and($finding->fix)->toContain('wpackagist-'.$word);

    $one = $check->run(new FakeEnvironment(...[$property => ['checked' => $now, 'waiting' => [], 'unfollowed' => 1]]));

    expect($one->outcome)->toBe(Outcome::Pass)->and($one->details[0])->toStartWith('1 '.$word.' is not followed');
})->with([
    'plugins' => [UpdatesCheck::plugins(), 'pluginUpdates', 'plugin'],
    'themes' => [UpdatesCheck::themes(), 'themeUpdates', 'theme'],
]);

it('reports unused code in production only, and not on a network', function (InactiveExtensionsCheck $check, string $property, string $word): void {
    $environment = static fn (?array $unused, string $type = 'production'): FakeEnvironment => new FakeEnvironment(...[$property => $unused, 'type' => $type]);
    $finding = $check->run($environment(['a', 'b', 'c', 'd', 'e', 'f']));

    expect($finding->severity)->toBe(Severity::Low)
        ->and($finding->summary)->toStartWith('6 '.$word.'s are installed')
        ->and($finding->details)->toBe(['a', 'b', 'c', 'd', 'e', 'and 1 more'])
        ->and($check->run($environment([]))->outcome)->toBe(Outcome::Pass)
        ->and($check->run($environment(null))->summary)->toContain('network')
        ->and($check->run($environment(['a'], 'local'))->outcome)->toBe(Outcome::Pass);
})->with([
    'plugins' => [InactiveExtensionsCheck::plugins(), 'inactivePlugins', 'plugin'],
    'themes' => [InactiveExtensionsCheck::themes(), 'unusedThemes', 'theme'],
]);
