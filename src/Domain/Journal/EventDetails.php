<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Journal;

/**
 * The few facts that go with an event (`from: editor`, `to: administrator`). Never a secret: a key that looks like one is dropped,
 * a value is cut, and the whole is small. What is kept is flat text and numbers, so that it can be shown without care.
 */
final class EventDetails
{
    private const int KEYS = 8;

    private const int VALUE = 120;

    private const int BYTES = 800;

    /** A name that says it holds a secret. */
    private const string SECRET = '/pass|secret|token|key|salt|hash|cookie|nonce|auth/i';

    /**
     * @param  array<mixed>  $details
     * @return array<string, string|int|bool>
     */
    public static function clean(array $details): array
    {
        $clean = [];

        foreach ($details as $key => $value) {
            $key = (string) $key;

            if (count($clean) >= self::KEYS || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) !== 1 || preg_match(self::SECRET, $key) === 1) {
                continue;
            }

            if (is_bool($value) || is_int($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value)) {
                $clean[$key] = self::text($value, self::VALUE);
            }
        }

        // However it was made, the whole stays small
        while ($clean !== [] && strlen((string) json_encode($clean)) > self::BYTES) {
            array_pop($clean);
        }

        return $clean;
    }

    /**
     * One line of text: no control or invisible character, cut to a length.
     */
    public static function text(string $text, int $length): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
