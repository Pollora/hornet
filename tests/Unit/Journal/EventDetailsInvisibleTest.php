<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Journal\EventDetails;

it('takes out what a person cannot see and a model can read', function (string $hidden): void {
    expect(EventDetails::text('ab'.$hidden.'cd', 20))->toBe('abcd');
})->with([
    'a tag character' => ["\u{E0041}\u{E0042}\u{E007F}"],
    'a zero width space' => ["\u{200B}"],
    'a soft hyphen' => ["\u{00AD}"],
    'a word joiner' => ["\u{2060}"],
    'a variation selector' => ["\u{FE0F}"],
    'a supplementary variation selector' => ["\u{E0100}"],
    'a combining grapheme joiner' => ["\u{034F}"],
    'a mongolian vowel separator' => ["\u{180E}"],
    'a bidirectional override' => ["\u{202E}"],
    'a private use character' => ["\u{E000}"],
    'a control' => ["\x00\x07\n"],
]);

it('keeps the text that is visible, accents and other scripts included', function (): void {
    expect(EventDetails::text('Café – 日本語 – Привет', 50))->toBe('Café – 日本語 – Привет');
});
