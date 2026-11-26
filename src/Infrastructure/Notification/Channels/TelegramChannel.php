<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification\Channels;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Notification\Text;

/**
 * The Telegram Bot API, `sendMessage` in HTML mode.
 *
 * The bot token is in the path of the URL, so the URL is a secret and is never printed. Text is escaped for HTML (`&`, `<`, `>`),
 * cut before it is escaped (a message holds 4096 characters after parsing), and the `@` is broken so that a user name in a finding
 * does not become a mention. The reply says what went wrong in its body: `ok`, and `parameters.retry_after` for a flood.
 *
 * @internal
 */
final class TelegramChannel extends HttpChannel
{
    private const int TEXT = 3500;

    public function __construct(
        string $name,
        Severity $minSeverity,
        HttpClient $http,
        Secret $token,
        private readonly string $chatId,
    ) {
        parent::__construct($name, $minSeverity, $http, Secret::of('https://api.telegram.org/bot'.$token->reveal().'/sendMessage'));
    }

    protected function request(Notification $notification): array
    {
        $text = '<b>'.self::escape(Text::clean($notification->subject()))."</b>\n".implode("\n", array_map(self::escape(...), Text::lines($notification, 250)))."\n".self::escape(Text::clean($notification->siteUrl));

        $payload = [
            'chat_id' => $this->chatId,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'text' => Text::truncate($text, self::TEXT),
        ];

        return [['Content-Type' => 'application/json'], $this->json($payload)];
    }

    protected function interpret(HttpResponse $response): Delivery
    {
        $data = json_decode($response->body, true);

        if ($response->status === 429) {
            $wait = is_array($data) && is_array($data['parameters'] ?? null) && is_int($data['parameters']['retry_after'] ?? null) ? $data['parameters']['retry_after'] : $response->retryAfter;

            return Delivery::retry('Telegram asked to slow down (HTTP 429).', $wait);
        }

        // Telegram answers 200 with ok: true; anything else is judged by the status
        if ($response->status === 200 && is_array($data) && ($data['ok'] ?? false) !== true) {
            return Delivery::failed('Telegram did not accept the message.');
        }

        return Delivery::fromStatus($response->status, $response->retryAfter);
    }

    private static function escape(string $text): string
    {
        return Text::defuseMentions(str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text));
    }
}
