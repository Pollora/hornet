<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Infrastructure\Notification\Text;
use Pollora\Hornet\Tests\Fixtures\Samples;

it('takes out control and invisible formatting characters', function (): void {
    expect(Text::clean("Hello\x00\x07 \u{202E}evil\u{200B}\nnext\tline  "))->toBe('Hello evil next line');
});

it('cuts to a length with an ellipsis, and leaves short text alone', function (): void {
    expect(Text::truncate('abcdefghij', 5))->toBe('abcd…')->and(Text::truncate('abc', 5))->toBe('abc')->and(Text::truncate('é€é€é€', 4))->toBe('é€é…');
});

it('cuts to a number of bytes at a character boundary', function (): void {
    $cut = Text::truncateBytes(str_repeat('€', 100), 50);

    expect(strlen($cut))->toBeLessThanOrEqual(50)->and(mb_check_encoding($cut, 'UTF-8'))->toBeTrue()->and($cut)->toEndWith('…')
        ->and(Text::truncateBytes('short', 50))->toBe('short');
});

it('breaks a mention without changing how the text reads', function (): void {
    expect(Text::defuseMentions('@everyone and @here'))->toBe("@\u{200B}everyone and @\u{200B}here");
});

it('lists the most serious alert first, with its details and fix', function (): void {
    $lines = Text::lines(Samples::notification([Samples::alert('a', Severity::Low, 'Low one'), Samples::alert('b', Severity::High, 'High one')]));

    expect($lines[0])->toBe('[HIGH] High one')->and($lines)->toContain('  - detail one')->toContain('  Fix: Turn it off.')->toContain('[LOW] Low one');
});

it('caps the alerts and the details, and counts the rest', function (): void {
    $alerts = array_map(static fn (int $i) => new Alert('k'.$i, Severity::Medium, 'Alert '.$i, ['1', '2', '3', '4', '5', '6', '7']), range(1, 13));
    $lines = Text::lines(Samples::notification($alerts));

    expect(array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '[MEDIUM]'))))->toHaveCount(10)
        ->and($lines[array_key_last($lines)])->toBe('… and 3 more.')
        ->and(array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '  - '))))->toHaveCount(50);
});

it('cleans the text of an alert before it is listed', function (): void {
    $lines = Text::lines(Samples::notification([new Alert('k', Severity::Low, "Title\r\n@everyone \u{202E}", ["detail\x00"])]));

    expect($lines)->toBe(['[LOW] Title @everyone', '  - detail']);
});
