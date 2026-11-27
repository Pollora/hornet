<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Notification\Channels\DiscordChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\MailChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\NtfyChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\SlackChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\TeamsChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\TelegramChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\WebhookChannel;
use Pollora\Hornet\Infrastructure\Notification\Text;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\FakeMailer;

/**
 * A notification whose every text carries the same hostile piece: the name of the site, its address, a title, a detail, a fix.
 */
function mutAppHostile(string $piece): Notification
{
    return new Notification('id1', 'name'.$piece, 'https://example.com/url'.$piece, [new Alert('k', Severity::High, 'title'.$piece, ['detail'.$piece], 'fix'.$piece)], new DateTimeImmutable('2026-11-25 10:00:00 UTC'));
}

/**
 * Sends it and gives back every text of the payload.
 *
 * @return array<string, mixed>
 */
function mutAppSent(string $channel, Notification $notification, ?FakeHttpClient &$http = null): array
{
    $http = new FakeHttpClient;
    $url = Secret::of('https://hooks.example.com/x');
    (match ($channel) {
        'slack' => new SlackChannel('c', Severity::Info, $http, $url),
        'teams' => new TeamsChannel('c', Severity::Info, $http, $url),
        'discord' => new DiscordChannel('c', Severity::Info, $http, $url),
        'telegram' => new TelegramChannel('c', Severity::Info, $http, Secret::of('t'), '1'),
        'ntfy' => new NtfyChannel('c', Severity::Info, $http, Secret::of('https://ntfy.example.com/topic'), null, false),
        'webhook' => new WebhookChannel('c', Severity::Info, $http, $url, Secret::of('k'), new FakeClock, false),
    })->send($notification);

    return $http->lastJson();
}

// Escaping

it('escapes what Slack reads as markup in the fallback, the section and the context', function (): void {
    $json = mutAppSent('slack', mutAppHostile(' <!channel> & >'));
    $escaped = ' &lt;!channel&gt; &amp; &gt;';

    expect($json['text'])->toContain('name'.$escaped)->not->toContain('<!')
        ->and($json['blocks'][1]['text']['text'])->toContain('title'.$escaped)->toContain('detail'.$escaped)->toContain('fix'.$escaped)->not->toContain('<!')
        ->and($json['blocks'][2]['elements'][0]['text'])->toBe('https://example.com/url'.$escaped)
        ->and($json['blocks'][2]['elements'][0]['verbatim'])->toBeTrue()
        // A plain text header is shown as it is
        ->and($json['blocks'][0]['text']['emoji'])->toBeFalse()
        ->and($json['blocks'][0]['text']['text'])->toContain('name <!channel> & >');
});

it('takes control and invisible characters out of what Slack, Teams, Discord and Telegram are sent', function (string $channel): void {
    $json = json_encode(mutAppSent($channel, mutAppHostile("\u{202E}\u{200D}\x07\r\nZZ")), JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain("\u{202E}")->not->toContain("\u{200D}")->not->toContain('\u0007')->not->toContain('\r')
        ->and($json)->toContain('name ZZ')->toContain('url ZZ');
})->with(['slack', 'teams', 'discord', 'telegram', 'ntfy']);

it('escapes each Markdown character for Teams, in the subject, the lines and the address', function (): void {
    $special = '\\`*_{}[]()#+!|<>~';
    $escaped = implode('', array_map(static fn (string $c): string => '\\'.$c, str_split($special)));
    $body = mutAppSent('teams', mutAppHostile($special.'@x'))['attachments'][0]['content']['body'];

    expect($body[0]['text'])->toContain('name'.$escaped."@\u{200B}x")
        ->and($body[1]['text'])->toContain('title'.$escaped)->toContain('detail'.$escaped)->toContain('fix'.$escaped)
        ->and($body[2]['text'])->toContain('url'.$escaped."@\u{200B}x")
        ->and($body[0]['wrap'])->toBeTrue()->and($body[2]['isSubtle'])->toBeTrue();
});

it('escapes each Markdown character for Discord, in the title and the description', function (): void {
    $special = '\\*_~|`>#[]()';
    $escaped = implode('', array_map(static fn (string $c): string => '\\'.$c, str_split($special)));
    $json = mutAppSent('discord', mutAppHostile($special.'@x'));

    expect($json['username'])->toBe('Hornet')
        ->and($json['embeds'][0]['title'])->toContain('name'.$escaped."@\u{200B}x")
        ->and($json['embeds'][0]['description'])->toContain('title'.$escaped)->toContain('detail'.$escaped)->toContain('fix'.$escaped)
        ->and($json['embeds'][0]['footer']['text'])->toContain('https://example.com/url');
});

it('escapes for HTML and breaks mentions everywhere in a Telegram message', function (): void {
    $text = mutAppSent('telegram', mutAppHostile(' <a>&@x'))['text'];
    $escaped = " &lt;a&gt;&amp;@\u{200B}x";

    expect($text)->toStartWith('<b>[Hornet] name'.$escaped)
        ->and($text)->toContain('title'.$escaped)->toContain('detail'.$escaped)->toContain('fix'.$escaped)
        ->and($text)->toEndWith("\nhttps://example.com/url".$escaped)
        ->and(explode("\n", $text)[0])->toEndWith('(high)</b>');
});

it('cleans the title, the message and the address of an ntfy message', function (): void {
    $json = mutAppSent('ntfy', mutAppHostile("\n\x07z"));

    expect($json['title'])->not->toContain("\n")->toContain('name z')
        ->and(explode("\n", $json['message']))->toBe(['[HIGH] title z', '  - detail z', '  Fix: fix z', 'https://example.com/url z'])
        ->and($json['tags'])->toBe(['rotating_light']);
});

it('tags an ntfy message by how bad it is', function (Severity $severity, string $tag): void {
    $notification = new Notification('i', 's', 'u', [new Alert('k', $severity, 't')], new DateTimeImmutable);

    expect(mutAppSent('ntfy', $notification)['tags'])->toBe([$tag]);
})->with([[Severity::Critical, 'rotating_light'], [Severity::Medium, 'warning'], [Severity::Info, 'warning']]);

// Limits

/**
 * Ten alerts whose every text is long.
 */
function mutAppLong(string $name = 'n'): Notification
{
    $alerts = array_map(static fn (int $n): Alert => new Alert('k'.$n, Severity::High, str_repeat('x', 900), array_fill(0, 5, str_repeat('y', 900))), range(1, 10));

    return new Notification('i', $name, str_repeat('u', 900), $alerts, new DateTimeImmutable);
}

it('cuts each text to what the service takes', function (): void {
    $long = mutAppLong(str_repeat('s', 900));

    $slack = mutAppSent('slack', $long);
    $discord = mutAppSent('discord', $long);
    $telegram = mutAppSent('telegram', $long);
    $teams = mutAppSent('teams', $long)['attachments'][0]['content']['body'];
    $ntfy = mutAppSent('ntfy', $long);

    expect(mb_strlen($slack['text']))->toBe(300)
        ->and(mb_strlen($slack['blocks'][0]['text']['text']))->toBe(150)
        ->and(mb_strlen($slack['blocks'][1]['text']['text']))->toBe(2800)
        ->and(mb_strlen($discord['embeds'][0]['title']))->toBe(250)
        ->and(mb_strlen($discord['embeds'][0]['description']))->toBe(3500)
        ->and(mb_strlen($discord['embeds'][0]['footer']['text']))->toBe(200)
        ->and(mb_strlen($telegram['text']))->toBe(3500)
        ->and(mb_strlen($teams[1]['text']))->toBe(3000)
        ->and(strlen($ntfy['title']))->toBe(250)
        ->and(strlen($ntfy['message']))->toBe(3900);
});

it('cuts each line before escaping it', function (string $channel, int $length): void {
    $notification = new Notification('i', 's', 'u', [new Alert('k', Severity::High, str_repeat('x', 900))], new DateTimeImmutable);
    $json = json_encode(mutAppSent($channel, $notification), JSON_UNESCAPED_UNICODE);
    preg_match('/x{5,}/', $json, $run);

    expect(strlen($run[0]))->toBe($length - 1);
})->with([['slack', 300], ['discord', 300], ['teams', 300], ['telegram', 250], ['ntfy', 250]]);

it('keeps slashes and accents as they are, and does not fail on bytes that are not text', function (): void {
    $http = null;
    mutAppSent('webhook', new Notification('i', "é/\xB1", 'u', [new Alert('k', Severity::Low, 't')], new DateTimeImmutable), $http);

    expect($http->posts[0]['body'])->toContain("\"é/\u{FFFD}\"");
});

it('posts to a webhook only where it may, private only when allowed', function (): void {
    $http = null;
    mutAppSent('slack', mutAppHostile(''), $http);

    expect($http->posts[0]['private'])->toBeFalse()->and($http->limits[0])->toBe(['maxBytes' => 4096, 'timeout' => 10]);
});

// Telegram answers

it('reads what Telegram says went wrong', function (HttpResponse $answer, array $expected): void {
    $http = new FakeHttpClient;
    $http->answers = [$answer];

    $delivery = (new TelegramChannel('g', Severity::Low, $http, Secret::of('t'), '1'))->send(mutAppHostile(''));

    expect([$delivery->delivered, $delivery->retryable, $delivery->retryAfter, $delivery->reason])->toBe($expected);
})->with([
    'a wait in the body' => [new HttpResponse(429, 5, '{"parameters":{"retry_after":17}}'), [false, true, 17, 'Telegram asked to slow down (HTTP 429).']],
    'a wait in the header only' => [new HttpResponse(429, 5, '{"parameters":{"retry_after":"x"}}'), [false, true, 5, 'Telegram asked to slow down (HTTP 429).']],
    'no body' => [new HttpResponse(429, 5, ''), [false, true, 5, 'Telegram asked to slow down (HTTP 429).']],
    'parameters that are not a list' => [new HttpResponse(429, 5, '{"parameters":"x"}'), [false, true, 5, 'Telegram asked to slow down (HTTP 429).']],
    'a refusal said with a 200' => [new HttpResponse(200, null, '{"description":"no"}'), [false, false, null, 'Telegram did not accept the message.']],
    'a server failure' => [new HttpResponse(502, 8, ''), [false, true, 8, 'The service failed (HTTP 502).']],
]);

// Mail

it('writes a mail with cleaned name and address, then the lines, then when', function (): void {
    $mailer = new FakeMailer;
    $notification = new Notification('i', "My\x07site", "https://e.co/\u{202E}x", [new Alert('k', Severity::Low, str_repeat('x', 900))], new DateTimeImmutable('2026-11-25 10:00:00 UTC'));

    (new MailChannel('m', Severity::Low, $mailer, ['a@example.com']))->send($notification);
    $lines = explode("\n", $mailer->mails[0]['body']);

    expect($lines[0])->toBe('My site (https://e.co/ x)')
        ->and($lines[1])->toBe('')
        ->and(mb_strlen($lines[2]))->toBe(mb_strlen('[LOW] ') + 500)
        ->and($lines[3])->toBe('')
        ->and($lines[4])->toBe('Sent by Hornet at 2026-11-25T10:00:00+00:00.')
        ->and($mailer->mails[0]['subject'])->toBe('[Hornet] My site: 1 security finding (low)')
        ->and((new MailChannel('team', Severity::High, $mailer, []))->name())->toBe('team')
        ->and((new MailChannel('team', Severity::High, $mailer, []))->minSeverity())->toBe(Severity::High);
});

it('says why a mail will be tried again', function (): void {
    $mailer = new FakeMailer;
    $mailer->works = false;

    expect((new MailChannel('m', Severity::Low, $mailer, ['a@example.com']))->send(mutAppHostile(''))->reason)->toBe('The site could not send the e-mail.');
});

// The text of a message

it('lists ten alerts, the worst first, five details each, and counts the rest', function (): void {
    $alerts = array_map(static fn (int $n): Alert => new Alert('k'.$n, Severity::Low, 'low '.$n, array_map(strval(...), range(1, 7))), range(1, 10));
    $alerts[] = new Alert('c', Severity::Critical, 'worst', fix: 'now');
    $lines = Text::lines(new Notification('i', 's', 'u', $alerts, new DateTimeImmutable));

    expect($lines[0])->toBe('[CRITICAL] worst')
        ->and($lines[1])->toBe('  Fix: now')
        ->and($lines[2])->toBe('[LOW] low 1')
        ->and(array_slice($lines, 3, 6))->toBe(['  - 1', '  - 2', '  - 3', '  - 4', '  - 5', '[LOW] low 2'])
        ->and($lines[array_key_last($lines)])->toBe('… and 1 more.')
        ->and($lines)->toHaveCount(2 + 9 * 6 + 1)
        ->and(Text::lines(new Notification('i', 's', 'u', array_slice($alerts, 0, 10), new DateTimeImmutable)))->each->not->toContain('more.');
});

it('cuts every line of an alert to 300 characters unless told otherwise', function (): void {
    $long = str_repeat('é', 400);
    $lines = Text::lines(new Notification('i', 's', 'u', [new Alert('k', Severity::Low, $long, [$long], $long)], new DateTimeImmutable));

    expect(array_map(mb_strlen(...), $lines))->toBe([6 + 300, 4 + 300, 7 + 300]);
});

it('cleans a text to one line', function (): void {
    expect(Text::clean("  a\u{2028}b\u{2029}c\t\u{200B}\u{FEFF}d   é  "))->toBe('a b c d é');
});

it('cuts at a length in characters, or in bytes, keeping whole characters and room for the ellipsis', function (): void {
    expect(Text::truncate('éééé', 4))->toBe('éééé')
        ->and(Text::truncate('ééééé', 4))->toBe('ééé…')
        ->and(Text::truncate('ab cdef', 4))->toBe('ab…')
        ->and(Text::truncate('abc', 0))->toBe('…')
        ->and(Text::truncateBytes('éé', 4))->toBe('éé')
        ->and(Text::truncateBytes('ééé', 4))->toBe('…')
        ->and(Text::truncateBytes('éééé', 7))->toBe('éé…')
        ->and(Text::truncateBytes('ab cdefgh', 6))->toBe('ab…')
        ->and(Text::truncateBytes('abcdef', 2))->toBe('…')
        ->and(Text::defuseMentions('@a@b'))->toBe("@\u{200B}a@\u{200B}b");
});

// The address of an ntfy server

it('posts to the root of the server, keeping its port and path, and names the topic', function (string $topic, string $root): void {
    $http = new FakeHttpClient;
    (new NtfyChannel('n', Severity::Low, $http, Secret::of($topic), null, false))->send(mutAppHostile(''));

    expect($http->posts[0]['url'])->toBe($root)->and($http->lastJson()['topic'])->toBe('topic');
})->with([
    ['https://ntfy.example.com/topic', 'https://ntfy.example.com/'],
    ['http://ntfy.example.com:8080/topic', 'http://ntfy.example.com:8080/'],
    ['https://example.com/a/b/topic', 'https://example.com/a/b/'],
    ['https://example.com/ntfy/topic/', 'https://example.com/ntfy/'],
]);
