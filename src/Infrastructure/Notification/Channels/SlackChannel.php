<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification\Channels;

use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Infrastructure\Notification\Text;

/**
 * A Slack incoming webhook: a header, then the alerts in sections.
 *
 * Text is escaped the way Slack asks (`&`, `<`, `>` only), which also makes `<!channel>` and `<!here>` inert, and every
 * text object is `verbatim`. The payload has no `link_names`, so `@name` is not turned into a mention.
 * Section text stops at 3000 characters in Slack: this stays well below.
 */
final class SlackChannel extends HttpChannel
{
    private const int SECTION = 2800;

    protected function request(Notification $notification): array
    {
        $text = implode("\n", array_map(self::escape(...), Text::lines($notification)));
        $heading = Text::truncate(Text::clean($notification->subject()), 150);

        $payload = [
            // The fallback of notifications, and what Slack refuses a message without
            'text' => Text::truncate(self::escape(Text::clean($notification->subject())), 300),
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $heading, 'emoji' => false]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'verbatim' => true, 'text' => Text::truncate($text, self::SECTION)]],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'verbatim' => true, 'text' => self::escape(Text::clean($notification->siteUrl))]]],
            ],
        ];

        return [['Content-Type' => 'application/json'], $this->json($payload)];
    }

    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
