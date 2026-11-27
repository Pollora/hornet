<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;
use Pollora\Hornet\Tests\Fixtures\ArrayTranslator;

/*
 * A sentence that is stored and said later: what is written, what is read back, and what is refused.
 */

it('stores only what a sentence has', function (): void {
    expect(Message::of('x')->toArray())->toBe(['one' => 'x'])
        ->and(Message::plural('%d a', '%d as', 2, 2)->toArray())->toBe(['one' => '%d a', 'many' => '%d as', 'count' => 2, 'args' => [2]])
        ->and(Message::of('%s!', Message::of('y'))->toArray())->toBe(['one' => '%s!', 'args' => [['one' => 'y']]])
        ->and(Message::join(' ', [Message::of('y')])->toArray())->toBe(['glue' => ' ', 'parts' => [['one' => 'y']]]);
});

it('keeps the values as a list', function (): void {
    expect(Message::of('%s', ...['name' => 'x'])->toArray()['args'])->toBe(['x'])
        ->and(Message::plural('%s', '%s', 1, ...['name' => 'x'])->toArray()['args'])->toBe(['x']);
});

it('reads back numbers, counts and nested sentences', function (): void {
    $message = Message::plural('%.1f and %s (%d)', '%.1f and %s (%d)', 2, 1.5, Message::of('y'), 2);

    expect(Message::fromArray($message->toArray()))->toEqual($message);
});

it('reads six levels of sentences inside sentences, and refuses a seventh', function (Closure $wrap): void {
    $build = static function (int $levels) use ($wrap): Message {
        $message = Message::of('deep');

        for ($i = 0; $i < $levels; $i++) {
            $message = $wrap($message);
        }

        return $message;
    };

    expect(Message::fromArray($build(6)->toArray())?->english())->toBe('deep')
        ->and(Message::fromArray($build(7)->toArray()))->toBeNull();
})->with([
    'parts' => [static fn (Message $inner): Message => Message::join('', [$inner])],
    'values' => [static fn (Message $inner): Message => Message::of('%s', $inner)],
]);

it('refuses a stored sentence with a part or a value that does not fit', function (array $stored): void {
    expect(Message::fromArray($stored))->toBeNull();
})->with([
    'a part' => [['glue' => '', 'parts' => [['nope' => 1]]]],
    'a value that is a broken sentence' => [['one' => '%s', 'args' => [['nope' => 1]]]],
    'a value that is a boolean' => [['one' => '%s', 'args' => [true]]],
]);

it('does not count a doubled percent sign as a value', function (): void {
    expect(Message::fromArray(['one' => '%%s is %s', 'args' => ['a']])?->english())->toBe('%s is a');
});

it('asks for the form of zero when a stored plural lost its number', function (): void {
    $translator = new class implements Translator
    {
        public ?int $asked = null;

        public function translate(string $text): string
        {
            return $text;
        }

        public function plural(string $one, string $many, int $count): string
        {
            $this->asked = $count;

            return $many;
        }
    };

    Message::fromArray(['one' => 'a', 'many' => 'as'])?->render($translator);

    expect($translator->asked)->toBe(0);
});

it('leaves a raw text as it is, whatever the translator says', function (): void {
    expect(Message::raw('x')->render(new ArrayTranslator(['%s' => 'y %s'])))->toBe('x');
});
