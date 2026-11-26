<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification\Channels;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Infrastructure\Notification\Text;

/**
 * A Discord webhook: one embed.
 *
 * `allowed_mentions.parse` is empty, so nothing is parsed into a ping whatever the text says (users, roles, `@everyone`),
 * and the `@` of the text is also broken. An embed description may hold 4096 characters and the whole message 6000: this stays below.
 *
 * @internal
 */
final class DiscordChannel extends HttpChannel
{
    private const int DESCRIPTION = 3500;

    protected function request(Notification $notification): array
    {
        $payload = [
            'username' => 'Hornet',
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => Text::truncate(self::escape(Text::clean($notification->subject())), 250),
                'description' => Text::truncate(implode("\n", array_map(self::escape(...), Text::lines($notification))), self::DESCRIPTION),
                'color' => self::color($notification->severity()),
                'footer' => ['text' => Text::truncate(Text::clean($notification->siteUrl), 200)],
            ]],
        ];

        return [['Content-Type' => 'application/json'], $this->json($payload)];
    }

    private static function escape(string $text): string
    {
        return Text::defuseMentions((string) preg_replace('/([\\\\*_~|`>#\[\]()])/', '\\\\$1', $text));
    }

    private static function color(Severity $severity): int
    {
        return match ($severity) {
            Severity::Critical => 0xB71C1C,
            Severity::High => 0xE53935,
            Severity::Medium => 0xF9A825,
            Severity::Low => 0x1E88E5,
            Severity::Info => 0x757575,
        };
    }
}
