<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification\Channels;

use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Infrastructure\Notification\Text;

/**
 * A Microsoft Teams workflow: the "When a Teams webhook request is received" trigger of Workflows (Power Automate).
 * The old Office 365 connectors were retired in May 2026.
 *
 * It sends an Adaptive Card. A 2xx means the workflow accepted the request, not that Teams posted it: the workflow can still fail
 * afterwards, and Hornet cannot see it. Text is Markdown there, so Markdown characters are escaped; a mention needs entities that the card does not carry.
 * A message may be 28 KB: the card stays far below.
 *
 * @internal
 */
final class TeamsChannel extends HttpChannel
{
    private const int BLOCK = 3000;

    protected function request(Notification $notification): array
    {
        $body = [
            ['type' => 'TextBlock', 'text' => self::escape(Text::clean($notification->subject())), 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => Text::truncate(implode("\n\n", array_map(self::escape(...), Text::lines($notification))), self::BLOCK), 'wrap' => true],
            ['type' => 'TextBlock', 'text' => self::escape(Text::clean($notification->siteUrl)), 'isSubtle' => true, 'wrap' => true],
        ];

        $payload = [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.2', 'body' => $body],
            ]],
        ];

        return [['Content-Type' => 'application/json'], $this->json($payload)];
    }

    private static function escape(string $text): string
    {
        return Text::defuseMentions((string) preg_replace('/([\\\\`*_{}\[\]()#+!|<>~])/', '\\\\$1', $text));
    }
}
