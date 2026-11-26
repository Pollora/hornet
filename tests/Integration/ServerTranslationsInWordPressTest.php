<?php

declare(strict_types=1);

use Pollora\Hornet\Application\AlertFactory;
use Pollora\Hornet\Application\I18n\FindingTranslator;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\AuditorFactory;
use Pollora\Hornet\Infrastructure\WordPress\I18n\WpTranslator;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;

/**
 * What the server writes, in another language, with the translation functions of a real WordPress and the files that are shipped.
 */
function shippedLocales(): array
{
    return array_map(static fn (string $file): array => [preg_replace('/^pollora-hornet-|\.l10n\.php$/', '', basename($file))], glob(dirname(__DIR__, 2).'/languages/*.l10n.php') ?: []);
}

function inLocale(string $locale, Closure $work): mixed
{
    $force = static fn (): string => $locale;
    add_filter('determine_locale', $force, 999);

    try {
        return $work();
    } finally {
        remove_filter('determine_locale', $force, 999);
    }
}

function auditReport(): Report
{
    return AuditorFactory::forWordPress(Settings::fromArray([]))->run(new WordPressEnvironment);
}

it('says the findings in each language that is shipped, and each message of the audit has a translation', function (string $locale): void {
    $report = auditReport();
    $translator = WpTranslator::forPackage();

    [$summaries, $labels] = inLocale($locale, static function () use ($report, $translator): array {
        $summaries = array_map(static fn (Finding $finding): array => [$finding->summary, FindingTranslator::summary($finding, $translator)], $report->findings);
        $labels = [Message::of('HTTPS')->render($translator), Message::of('Not scanned yet.')->render($translator)];

        return [$summaries, $labels];
    });

    $untranslated = array_filter($summaries, static fn (array $pair): bool => $pair[0] === $pair[1]);

    // A check that has nothing but a name or a version to say may read the same; the sentences must not
    expect($labels[1])->not->toBe('Not scanned yet.')->and(count($untranslated))->toBeLessThanOrEqual(1)
        ->and(array_column($summaries, 0))->each->toBeString();
})->with(shippedLocales());

it('keeps the plural forms of the language', function (): void {
    $translator = WpTranslator::forPackage();
    $say = static fn (int $n): string => inLocale('ru_RU', static fn (): string => Message::plural('%d minute ago', '%d minutes ago', $n, $n)->render($translator));
    $server = static fn (int $n): string => inLocale('pl_PL', static fn (): string => Message::plural('kept %d day', 'kept %d days', $n, $n)->render($translator));

    expect($server(1))->not->toBe($server(5))->and($server(2))->not->toBe($server(5))->and($say(1))->toContain('1');
});

it('speaks English again when the language has nothing, and changes language when the person does', function (): void {
    $translator = WpTranslator::forPackage();
    $say = static fn (string $locale): string => inLocale($locale, static fn (): string => Message::of('Not scanned yet.')->render($translator));

    expect($say('fr_FR'))->not->toBe('Not scanned yet.')->and($say('de_DE'))->not->toBe($say('fr_FR'))->and($say('xx_XX'))->toBe('Not scanned yet.')->and($say('fr_FR'))->not->toBe('Not scanned yet.');
});

it('keeps everything it needs to say a finding after it was stored, for every finding of this site', function (): void {
    $report = auditReport();

    foreach ($report->findings as $finding) {
        $read = Finding::fromArray(json_decode((string) json_encode($finding->toStoredArray()), true));

        expect(array_keys($read->messages))->toBe(array_keys($finding->messages), $finding->checkId)
            ->and($read->summaryMessage()->english())->toBe($finding->summary);
    }
});

it('writes a notification in the language of the site and keeps the key that tells old news from new', function (): void {
    $report = auditReport();
    $english = AlertFactory::fromReport($report);
    $french = inLocale('fr_FR', static fn (): array => AlertFactory::fromReport($report, WpTranslator::forPackage()));

    expect(array_map(static fn ($alert): string => $alert->key, $french))->toBe(array_map(static fn ($alert): string => $alert->key, $english))
        ->and($french)->not->toBe([])->and($french[0]->title)->not->toBe($english[0]->title);
});
