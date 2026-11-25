<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
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
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\FakeMailer;
use Pollora\Hornet\Tests\Fixtures\Samples;

/** A text that tries every trick: a broadcast, a mention, markup, a link, a control character. */
function hostile(): Notification
{
    return Samples::notification([new Alert('k', Severity::High, '<!channel> @everyone <@123> <b>bold</b> [x](http://evil.test) *star* `code`', ["<script>alert(1)</script>\x07", '&amp; @here'], '<!here> run it')]);
}

it('reads every channel as a name and a severity', function (): void {
    $channel = new SlackChannel('team', Severity::High, new FakeHttpClient, Secret::of('https://hooks.slack.com/x'));

    expect($channel->name())->toBe('team')->and($channel->minSeverity())->toBe(Severity::High);
});

describe('http channels', function (): void {
    it('treats a network error as something that can pass, without naming the address', function (): void {
        $http = new FakeHttpClient;
        $http->answers = [new TransportException('The request failed (http_request_failed).')];

        $delivery = (new SlackChannel('s', Severity::Low, $http, Secret::of('https://hooks.slack.com/services/SECRET')))->send(Samples::notification());

        expect($delivery->delivered)->toBeFalse()->and($delivery->retryable)->toBeTrue()->and($delivery->reason)->not->toContain('SECRET');
    });

    it('reads the status of the answer', function (): void {
        $http = new FakeHttpClient;
        $http->answers = [new HttpResponse(200), new HttpResponse(429, 12), new HttpResponse(404)];
        $channel = new SlackChannel('s', Severity::Low, $http, Secret::of('https://hooks.slack.com/x'));

        expect($channel->send(Samples::notification())->delivered)->toBeTrue();
        $limited = $channel->send(Samples::notification());
        expect($limited->retryable)->toBeTrue()->and($limited->retryAfter)->toBe(12);
        $gone = $channel->send(Samples::notification());
        expect($gone->retryable)->toBeFalse()->and($gone->reason)->not->toContain('hooks.slack.com');
    });
});

describe('slack', function (): void {
    it('sends a header, a section and the site, with a text fallback', function (): void {
        $http = new FakeHttpClient;
        (new SlackChannel('s', Severity::Low, $http, Secret::of('https://hooks.slack.com/x')))->send(Samples::notification());
        $json = $http->lastJson();

        expect($http->posts[0]['url'])->toBe('https://hooks.slack.com/x')
            ->and($http->posts[0]['headers'])->toBe(['Content-Type' => 'application/json'])
            ->and($json['text'])->toContain('My site')
            ->and(array_column($json['blocks'], 'type'))->toBe(['header', 'section', 'context'])
            ->and($json['blocks'][0]['text']['type'])->toBe('plain_text')
            ->and($json['blocks'][1]['text']['verbatim'])->toBeTrue()
            ->and($json)->not->toHaveKey('link_names');
    });

    it('makes a broadcast or a markup inert', function (): void {
        $http = new FakeHttpClient;
        (new SlackChannel('s', Severity::Low, $http, Secret::of('https://hooks.slack.com/x')))->send(hostile());
        $section = $http->lastJson()['blocks'][1]['text']['text'];

        expect($section)->toContain('&lt;!channel&gt;')->toContain('&lt;!here&gt;')->toContain('&lt;script&gt;')->toContain('&amp;amp;')
            ->and($section)->not->toContain('<!channel>')->not->toContain('<!here>')->not->toContain('<script>')->not->toContain("\x07");
    });

    it('keeps below the limits of the service', function (): void {
        $http = new FakeHttpClient;
        $long = array_map(static fn (int $i) => new Alert('k'.$i, Severity::High, str_repeat('x', 400), array_fill(0, 5, str_repeat('y', 400))), range(1, 12));
        (new SlackChannel('s', Severity::Low, $http, Secret::of('https://hooks.slack.com/x')))->send(Samples::notification($long));
        $json = $http->lastJson();

        expect(mb_strlen($json['blocks'][1]['text']['text']))->toBeLessThanOrEqual(3000)->and(mb_strlen($json['blocks'][0]['text']['text']))->toBeLessThanOrEqual(150);
    });
});

describe('teams', function (): void {
    it('sends an adaptive card in the shape of the workflow trigger', function (): void {
        $http = new FakeHttpClient;
        (new TeamsChannel('t', Severity::Low, $http, Secret::of('https://prod.example.logic.azure.com/x')))->send(Samples::notification());
        $json = $http->lastJson();

        expect($json['type'])->toBe('message')
            ->and($json['attachments'][0]['contentType'])->toBe('application/vnd.microsoft.card.adaptive')
            ->and($json['attachments'][0]['contentUrl'])->toBeNull()
            ->and($json['attachments'][0]['content']['type'])->toBe('AdaptiveCard')
            ->and($json['attachments'][0]['content']['version'])->toBe('1.2')
            ->and(array_unique(array_column($json['attachments'][0]['content']['body'], 'type')))->toBe(['TextBlock']);
    });

    it('escapes the markdown and breaks the mentions', function (): void {
        $http = new FakeHttpClient;
        (new TeamsChannel('t', Severity::Low, $http, Secret::of('https://x.logic.azure.com/x')))->send(hostile());
        $text = $http->lastJson()['attachments'][0]['content']['body'][1]['text'];

        expect($text)->toContain('\\*star\\*')->toContain('\\[x\\]\\(http')->toContain("@\u{200B}everyone")->not->toContain('<b>')->not->toContain('@everyone');
    });

    it('stays well below 28 KB', function (): void {
        $http = new FakeHttpClient;
        $long = array_map(static fn (int $i) => new Alert('k'.$i, Severity::High, str_repeat('x', 1000), array_fill(0, 9, str_repeat('y', 1000))), range(1, 30));
        (new TeamsChannel('t', Severity::Low, $http, Secret::of('https://x.logic.azure.com/x')))->send(Samples::notification($long));

        expect(strlen($http->posts[0]['body']))->toBeLessThan(28000);
    });
});

describe('discord', function (): void {
    it('sends one embed and lets nothing be parsed into a ping', function (): void {
        $http = new FakeHttpClient;
        (new DiscordChannel('d', Severity::Low, $http, Secret::of('https://discord.com/api/webhooks/1/abc')))->send(hostile());
        $json = $http->lastJson();

        expect($json['allowed_mentions'])->toBe(['parse' => []])
            ->and($json['embeds'])->toHaveCount(1)
            ->and($json['embeds'][0]['color'])->toBe(0xE53935)
            ->and($json['embeds'][0]['description'])->toContain("@\u{200B}everyone")->not->toContain('@everyone')->toContain('\\*star\\*');
    });

    it('stays within the limits of an embed', function (): void {
        $http = new FakeHttpClient;
        $long = array_map(static fn (int $i) => new Alert('k'.$i, Severity::Critical, str_repeat('x', 400), array_fill(0, 5, str_repeat('y', 400))), range(1, 12));
        (new DiscordChannel('d', Severity::Low, $http, Secret::of('https://discord.com/api/webhooks/1/abc')))->send(Samples::notification($long));
        $embed = $http->lastJson()['embeds'][0];

        expect(mb_strlen($embed['description']))->toBeLessThanOrEqual(4096)->and(mb_strlen($embed['title']))->toBeLessThanOrEqual(256)
            ->and(mb_strlen($embed['title']) + mb_strlen($embed['description']) + mb_strlen($embed['footer']['text']))->toBeLessThan(6000);
    });

    it('colours the embed by severity', function (Severity $severity, int $color): void {
        $http = new FakeHttpClient;
        (new DiscordChannel('d', Severity::Info, $http, Secret::of('https://discord.com/api/webhooks/1/abc')))->send(Samples::notification([Samples::alert('k', $severity)]));

        expect($http->lastJson()['embeds'][0]['color'])->toBe($color);
    })->with([[Severity::Critical, 0xB71C1C], [Severity::High, 0xE53935], [Severity::Medium, 0xF9A825], [Severity::Low, 0x1E88E5], [Severity::Info, 0x757575]]);
});

describe('telegram', function (): void {
    it('builds the URL from the token and escapes for HTML', function (): void {
        $http = new FakeHttpClient;
        (new TelegramChannel('g', Severity::Low, $http, Secret::of('123:ABC'), '-1001'))->send(hostile());
        $json = $http->lastJson();

        expect($http->posts[0]['url'])->toBe('https://api.telegram.org/bot123:ABC/sendMessage')
            ->and($json['chat_id'])->toBe('-1001')
            ->and($json['parse_mode'])->toBe('HTML')
            ->and($json['link_preview_options'])->toBe(['is_disabled' => true])
            ->and($json['text'])->toContain('&lt;script&gt;')->toContain("@\u{200B}everyone")->toContain('<b>[Hornet] My site')->not->toContain('<script>')
            // The one tag is the bold subject Hornet wrote: the <b> of the finding is escaped
            ->and(substr_count($json['text'], '<b>'))->toBe(1);
    });

    it('stays below 4096 characters', function (): void {
        $http = new FakeHttpClient;
        $long = array_map(static fn (int $i) => new Alert('k'.$i, Severity::High, str_repeat('<', 280), []), range(1, 12));
        (new TelegramChannel('g', Severity::Low, $http, Secret::of('123:ABC'), '-1001'))->send(Samples::notification($long));

        expect(mb_strlen($http->lastJson()['text']))->toBeLessThanOrEqual(4096);
    });

    it('reads the flood wait from the body of a 429', function (): void {
        $http = new FakeHttpClient;
        $http->answers = [new HttpResponse(429, null, '{"ok":false,"error_code":429,"parameters":{"retry_after":17}}')];
        $delivery = (new TelegramChannel('g', Severity::Low, $http, Secret::of('t'), '1'))->send(Samples::notification());

        expect($delivery->retryable)->toBeTrue()->and($delivery->retryAfter)->toBe(17);
    });

    it('does not trust a 200 whose body says no', function (): void {
        $http = new FakeHttpClient;
        $http->answers = [new HttpResponse(200, null, '{"ok":false}'), new HttpResponse(200, null, '{"ok":true,"result":{}}'), new HttpResponse(403, null, '{"ok":false}')];
        $channel = new TelegramChannel('g', Severity::Low, $http, Secret::of('t'), '1');

        expect($channel->send(Samples::notification())->delivered)->toBeFalse()
            ->and($channel->send(Samples::notification())->delivered)->toBeTrue()
            ->and($channel->send(Samples::notification())->retryable)->toBeFalse();
    });
});

describe('ntfy', function (): void {
    it('posts to the root of the server with the topic in the body', function (): void {
        $http = new FakeHttpClient;
        (new NtfyChannel('n', Severity::Low, $http, Secret::of('https://ntfy.sh/my-alerts'), null, false))->send(Samples::notification([Samples::alert('k', Severity::High)]));
        $json = $http->lastJson();

        expect($http->posts[0]['url'])->toBe('https://ntfy.sh/')
            ->and($http->posts[0]['private'])->toBeFalse()
            ->and($http->posts[0]['headers'])->toBe(['Content-Type' => 'application/json'])
            ->and($json['topic'])->toBe('my-alerts')
            ->and($json['priority'])->toBe(4)
            ->and(array_keys($json))->toBe(['topic', 'title', 'message', 'priority', 'tags']);
    });

    it('keeps the path of a server that lives under one, and sends the token as a bearer', function (): void {
        $http = new FakeHttpClient;
        (new NtfyChannel('n', Severity::Low, $http, Secret::of('http://10.0.0.5:8080/ntfy/alerts'), Secret::of('tk_secret'), true))->send(Samples::notification());

        expect($http->posts[0]['url'])->toBe('http://10.0.0.5:8080/ntfy/')
            ->and($http->posts[0]['headers']['Authorization'])->toBe('Bearer tk_secret')
            ->and($http->posts[0]['private'])->toBeTrue()
            ->and($http->lastJson()['topic'])->toBe('alerts');
    });

    it('maps the severity to a priority', function (Severity $severity, int $priority): void {
        $http = new FakeHttpClient;
        (new NtfyChannel('n', Severity::Info, $http, Secret::of('https://ntfy.sh/t'), null, false))->send(Samples::notification([Samples::alert('k', $severity)]));

        expect($http->lastJson()['priority'])->toBe($priority);
    })->with([[Severity::Critical, 5], [Severity::High, 4], [Severity::Medium, 3], [Severity::Low, 2], [Severity::Info, 1]]);

    it('cuts the message below 4096 bytes so that it never becomes an attachment, and sends plain text', function (): void {
        $http = new FakeHttpClient;
        $long = array_map(static fn (int $i) => new Alert('k'.$i, Severity::High, str_repeat('€', 250), array_fill(0, 5, str_repeat('€', 250))), range(1, 12));
        (new NtfyChannel('n', Severity::Low, $http, Secret::of('https://ntfy.sh/t'), null, false))->send(Samples::notification($long));
        $json = $http->lastJson();

        expect(strlen($json['message']))->toBeLessThan(4096)->and($json)->not->toHaveKeys(['markdown', 'click', 'actions', 'attach', 'email', 'call']);
    });
});

describe('webhook', function (): void {
    it('signs the timestamp and the raw body', function (): void {
        $http = new FakeHttpClient;
        $clock = new FakeClock('2026-11-25 10:00:00');
        (new WebhookChannel('w', Severity::Low, $http, Secret::of('https://siem.example.com/in'), Secret::of('the-key'), $clock, false))->send(Samples::notification());
        $post = $http->posts[0];
        $timestamp = (string) $clock->now()->getTimestamp();

        expect($post['headers']['X-Hornet-Timestamp'])->toBe($timestamp)
            ->and($post['headers']['X-Hornet-Id'])->toBe('abcdef0123456789')
            ->and($post['headers']['X-Hornet-Signature'])->toBe('sha256='.hash_hmac('sha256', $timestamp.'.'.$post['body'], 'the-key'))
            ->and($post['headers']['Content-Type'])->toBe('application/json');
    });

    it('sends the documented body', function (): void {
        $http = new FakeHttpClient;
        (new WebhookChannel('w', Severity::Low, $http, Secret::of('https://siem.example.com/in'), Secret::of('k'), new FakeClock, true))->send(Samples::notification());
        $json = $http->lastJson();

        expect($json['version'])->toBe(1)->and($json['event'])->toBe('hornet.alert')->and($json['id'])->toBe('abcdef0123456789')
            ->and($json['site'])->toBe(['name' => 'My site', 'url' => 'https://example.com'])
            ->and($json['severity'])->toBe('medium')
            ->and($json['alerts'][0])->toBe(['key' => 'finding:xml-rpc:medium', 'severity' => 'medium', 'title' => 'XML-RPC is enabled.', 'details' => ['detail one'], 'fix' => 'Turn it off.'])
            ->and($http->posts[0]['private'])->toBeTrue()
            ->and($json['sent_at'])->toBe('2026-11-25T10:00:00+00:00');
    });

    it('never puts the signing key in the request', function (): void {
        $http = new FakeHttpClient;
        (new WebhookChannel('w', Severity::Low, $http, Secret::of('https://siem.example.com/in'), Secret::of('the-key'), new FakeClock, false))->send(Samples::notification());

        expect(json_encode($http->posts))->not->toContain('the-key');
    });
});

describe('mail', function (): void {
    it('sends plain text to every address', function (): void {
        $mailer = new FakeMailer;
        $delivery = (new MailChannel('m', Severity::Low, $mailer, ['a@example.com', 'b@example.com']))->send(Samples::notification());

        expect($delivery->delivered)->toBeTrue()
            ->and($mailer->mails[0]['to'])->toBe(['a@example.com', 'b@example.com'])
            ->and($mailer->mails[0]['subject'])->toBe('[Hornet] My site: 1 security finding (medium)')
            ->and($mailer->mails[0]['body'])->toContain('My site (https://example.com)')->toContain('[MEDIUM] XML-RPC is enabled.');
    });

    it('tries again when the mailer says no', function (): void {
        $mailer = new FakeMailer;
        $mailer->works = false;

        expect((new MailChannel('m', Severity::Low, $mailer, ['a@example.com']))->send(Samples::notification())->retryable)->toBeTrue();
    });

    it('keeps a line break out of the subject', function (): void {
        $mailer = new FakeMailer;
        (new MailChannel('m', Severity::Low, $mailer, ['a@example.com']))->send(Samples::notification());
        $notification = new Notification('i', "Evil\r\nBcc: x@y.z", 'https://e.co', [Samples::alert()], new DateTimeImmutable);
        (new MailChannel('m', Severity::Low, $mailer, ['a@example.com']))->send($notification);

        expect($mailer->mails[1]['subject'])->not->toContain("\n")->not->toContain("\r");
    });
});
