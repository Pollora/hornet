<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Tests\Fixtures\ArrayTranslator;

$french = static fn (): ArrayTranslator => new ArrayTranslator([
    'A user is named "%s".' => 'Un compte s’appelle « %s ».',
    '%d plugin has changed.' => ['%d extension a changé.', '%d extensions ont changé.'],
    'Changed: %s.' => 'Modifié : %s.',
    'Delete it.' => 'Supprimez-le.',
]);

it('says the English of the source, and the other language when asked', function () use ($french): void {
    $message = Message::of('A user is named "%s".', 'admin');

    expect($message->english())->toBe('A user is named "admin".')->and($message->render($french()))->toBe('Un compte s’appelle « admin ».');
});

it('chooses the plural form with the number', function () use ($french): void {
    $one = Message::plural('%d plugin has changed.', '%d plugins have changed.', 1, 1);
    $two = Message::plural('%d plugin has changed.', '%d plugins have changed.', 2, 2);

    expect($one->english())->toBe('1 plugin has changed.')->and($two->english())->toBe('2 plugins have changed.')
        ->and($one->render($french()))->toBe('1 extension a changé.')->and($two->render($french()))->toBe('2 extensions ont changé.');
});

it('never reads what comes from the site as a format, nor translates it', function () use ($french): void {
    $path = 'uploads/%s%d%n 100% evil.php';
    $message = Message::of('Changed: %s.', $path);

    expect($message->english())->toBe("Changed: {$path}.")->and($message->render($french()))->toBe("Modifié : {$path}.")
        ->and(Message::raw('Delete it.')->render($french()))->toBe('Delete it.')
        ->and(Message::raw('50% %s')->render($french()))->toBe('50% %s');
});

it('keeps the English when a translation lost or invented a value, or cannot be used', function (string $broken): void {
    $message = Message::of('A user is named "%s".', 'admin');

    expect($message->render(new ArrayTranslator(['A user is named "%s".' => $broken])))->toBe('A user is named "admin".');
})->with(['lost' => ['Un compte.'], 'one more' => ['%s et %s'], 'another kind' => ['Compte %d'], 'a lone percent and a lost value' => ['100 % des comptes']]);

it('keeps a sentence with a lone percent that is not a value', function (): void {
    $translator = new ArrayTranslator(['All of them.' => '100 % des cas.']);

    expect(Message::of('All of them.')->render($translator))->toBe('100 % des cas.');
});

it('says nested sentences and lists of sentences in the same language', function () use ($french): void {
    $nested = Message::of('Changed: %s.', Message::of('Delete it.'));
    $joined = Message::join(' ', [Message::of('Delete it.'), Message::of('Changed: %s.', 'x')]);

    expect($nested->render($french()))->toBe('Modifié : Supprimez-le..')->and($joined->render($french()))->toBe('Supprimez-le. Modifié : x.')->and($joined->english())->toBe('Delete it. Changed: x.');
});

it('can be stored and read back, and reads nothing that does not fit', function () use ($french): void {
    $message = Message::join(' ', [Message::plural('%d plugin has changed.', '%d plugins have changed.', 2, 2), Message::of('Changed: %s.', Message::raw('a%b'))]);
    $read = Message::fromArray(json_decode((string) json_encode($message->toArray()), true));

    expect($read?->english())->toBe($message->english())->and($read?->render($french()))->toBe($message->render($french()))
        ->and(Message::fromArray('text'))->toBeNull()
        ->and(Message::fromArray(['one' => 'a %s b %s', 'args' => ['x']]))->toBeNull()
        ->and(Message::fromArray(['one' => 'a', 'args' => [[true]]]))->toBeNull()
        ->and(Message::fromArray(['glue' => 3, 'parts' => []]))->toBeNull();
});

it('keeps a finding English as it was, and translatable after a stored scan', function () use ($french): void {
    $finding = Finding::fail('admin-account', Severity::Medium, Message::of('A user is named "%s".', 'admin'), ['plain line', Message::of('Changed: %s.', 'a.php')], Message::of('Delete it.'));
    $stored = json_decode((string) json_encode((new ScanSnapshot(new DateTimeImmutable('2026-11-26'), ['admin-account' => $finding], [], []))->toArray()), true);
    $read = ScanSnapshot::fromArray($stored)->findings['admin-account'];

    expect($finding->summary)->toBe('A user is named "admin".')->and($finding->toArray())->not->toHaveKey('i18n')
        ->and($read->summaryMessage()->render($french()))->toBe('Un compte s’appelle « admin ».')
        ->and($read->detailMessages()[0]->render($french()))->toBe('plain line')
        ->and($read->detailMessages()[1]->render($french()))->toBe('Modifié : a.php.')
        ->and($read->fixMessage()?->render($french()))->toBe('Supprimez-le.');
});

it('reads a scan that was stored before there were messages, and drops a message that says something else than the text', function () use ($french): void {
    $old = ['check' => 'x', 'outcome' => 'fail', 'severity' => 'high', 'summary' => 'A user is named "admin".', 'details' => [], 'fix' => null];
    $lying = [...$old, 'i18n' => ['summary' => Message::of('Delete it.')->toArray()]];

    expect(Finding::fromArray($old)->summaryMessage()->render($french()))->toBe('A user is named "admin".')
        ->and(Finding::fromArray($lying)->summaryMessage()->render($french()))->toBe('A user is named "admin".');
});

it('lets a translation put the values in another order with numbered placeholders, and refuses one that takes another value', function (): void {
    $message = Message::of('%d failures in %d seconds', 5, 900);
    $reordered = new ArrayTranslator(['%d failures in %d seconds' => '%2$d秒間に%1$d回の失敗']);
    $wrong = new ArrayTranslator(['%d failures in %d seconds' => '%2$d秒間に%2$d回の失敗']);

    expect($message->render($reordered))->toBe('900秒間に5回の失敗')->and($message->render($wrong))->toBe('5 failures in 900 seconds');
});
