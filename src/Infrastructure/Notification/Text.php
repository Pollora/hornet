<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification;

use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\Notification;

/**
 * Makes text that comes from a site safe to hand to a chat service.
 *
 * Everything in an alert is data: a plugin name, a file name or a user login may hold a mention (`@everyone`), a link or
 * markup. Each channel escapes for its own syntax; this class does what they all need first: take out control and
 * invisible formatting characters (they hide text and reverse its direction), and cut to a length.
 */
final class Text
{
    /** The most alerts a message lists; the rest is counted. */
    public const int MAX_ALERTS = 10;

    /** The most detail lines under one alert. */
    public const int MAX_DETAILS = 5;

    private const string ELLIPSIS = '…';

    /**
     * One line of plain text: no control character, no invisible formatting character, no line break.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Cuts to at most $length characters, with an ellipsis. Cut the raw text, then escape: cutting escaped text can leave half an entity.
     */
    public static function truncate(string $text, int $length): string
    {
        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, max($length - 1, 0))).self::ELLIPSIS;
    }

    /**
     * Cuts to at most $bytes bytes, at a character boundary.
     */
    public static function truncateBytes(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        return rtrim(mb_strcut($text, 0, max($bytes - strlen(self::ELLIPSIS), 0))).self::ELLIPSIS;
    }

    /**
     * Breaks a mention without changing how the text reads: a zero-width space after the `@`.
     */
    public static function defuseMentions(string $text): string
    {
        return str_replace('@', "@\u{200B}", $text);
    }

    /**
     * The lines of a message as plain text, whatever the channel: one block per alert, the most serious first.
     *
     * @return list<string>
     */
    public static function lines(Notification $notification, int $lineLength = 300): array
    {
        $alerts = $notification->alerts;
        usort($alerts, static fn (Alert $a, Alert $b): int => $b->severity->rank() <=> $a->severity->rank());
        $lines = [];

        foreach (array_slice($alerts, 0, self::MAX_ALERTS) as $alert) {
            $lines[] = sprintf('[%s] %s', strtoupper($alert->severity->value), self::truncate(self::clean($alert->title), $lineLength));

            foreach (array_slice($alert->details, 0, self::MAX_DETAILS) as $detail) {
                $lines[] = '  - '.self::truncate(self::clean($detail), $lineLength);
            }

            if ($alert->fix !== null) {
                $lines[] = '  Fix: '.self::truncate(self::clean($alert->fix), $lineLength);
            }
        }

        if (count($alerts) > self::MAX_ALERTS) {
            $lines[] = sprintf('… and %d more.', count($alerts) - self::MAX_ALERTS);
        }

        return $lines;
    }
}
