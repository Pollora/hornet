<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\I18n;

use Throwable;

/**
 * A sentence that can be said in another language later: an English template, written in the source, and the values that go in it.
 *
 * Nothing is translated when it is made: what the audit finds is stored by a cron and read by people who speak different languages, so
 * it is translated where it is shown, with the language of the person who looks. The English text stays what is stored and compared.
 *
 * Only a literal of the source goes in the template (the translations are extracted from these calls). Whatever comes from the site, a
 * file name, a slug, something somebody typed, goes in the values: it is never read as a format, and never translated.
 */
final readonly class Message
{
    /**
     * @param  list<string|int|float|Message>  $args
     * @param  list<Message>  $parts
     */
    private function __construct(
        public string $one,
        public ?string $many = null,
        public ?int $count = null,
        public array $args = [],
        public ?string $glue = null,
        public array $parts = [],
    ) {}

    /**
     * `Message::of('The login "%s" exists.', $login)`. The template is a literal.
     */
    public static function of(string $template, string|int|float|Message ...$args): self
    {
        return new self($template, null, null, array_values($args));
    }

    /**
     * A sentence that changes with a number: `Message::plural('%d plugin has changed.', '%d plugins have changed.', $n, $n)`.
     * The number is given twice when it is also written: once to choose the form, then among the values.
     */
    public static function plural(string $one, string $many, int $count, string|int|float|Message ...$args): self
    {
        return new self($one, $many, $count, array_values($args));
    }

    /**
     * A text that was marked where it is written, with {@see mark()}, and reaches here as a variable (one of a closed list: a reason a
     * file is suspicious, the name of an exposed file). Never for text that comes from the site.
     */
    public static function known(string $marked): self
    {
        return new self($marked);
    }

    /**
     * Text that is not translated: a name, a path, what the site or a person wrote.
     */
    public static function raw(string $text): self
    {
        return new self('%s', null, null, [$text]);
    }

    /**
     * Several sentences, one after the other.
     *
     * @param  list<Message>  $parts
     */
    public static function join(string $glue, array $parts): self
    {
        return new self('', null, null, [], $glue, $parts);
    }

    /**
     * Marks a fixed text (a label) for translation, and gives it back as it is.
     */
    public static function mark(string $text): string
    {
        return $text;
    }

    public function english(): string
    {
        return $this->render(null);
    }

    /**
     * The sentence in the language of the translator; in English for what it cannot say. A translation whose placeholders do not match
     * the template is not used: it would lose a value, or fail.
     */
    public function render(?Translator $translator): string
    {
        if ($this->glue !== null) {
            return implode($this->glue, array_map(static fn (Message $part): string => $part->render($translator), $this->parts));
        }

        $english = $this->many !== null && $this->count !== 1 ? $this->many : $this->one;
        $template = $english;

        if ($translator instanceof Translator && $this->one !== '%s') {
            $translated = $this->many === null ? $translator->translate($this->one) : $translator->plural($this->one, $this->many, $this->count ?? 0);

            if (self::placeholders($translated) === self::placeholders($english)) {
                $template = $translated;
            }
        }

        if ($this->args === []) {
            return $template;
        }

        $args = array_map(static fn (string|int|float|Message $arg): string|int|float => $arg instanceof self ? $arg->render($translator) : $arg, $this->args);

        try {
            return vsprintf($template, $args);
        } catch (Throwable) {
            return vsprintf($english, $args);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->glue !== null) {
            return ['glue' => $this->glue, 'parts' => array_map(static fn (Message $part): array => $part->toArray(), $this->parts)];
        }

        return array_filter([
            'one' => $this->one,
            'many' => $this->many,
            'count' => $this->count,
            'args' => array_map(static fn (string|int|float|Message $arg): string|int|float|array => $arg instanceof self ? $arg->toArray() : $arg, $this->args),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Reads what {@see toArray()} wrote; null when it does not fit (a stored value may have been cut or edited).
     */
    public static function fromArray(mixed $value, int $depth = 0): ?self
    {
        if (! is_array($value) || $depth > 6) {
            return null;
        }

        if (isset($value['glue'])) {
            $parts = [];

            foreach (is_array($value['parts'] ?? null) ? $value['parts'] : [] as $stored) {
                $part = self::fromArray($stored, $depth + 1);

                if (! $part instanceof self) {
                    return null;
                }

                $parts[] = $part;
            }

            return is_string($value['glue']) ? self::join($value['glue'], $parts) : null;
        }

        if (! is_string($value['one'] ?? null)) {
            return null;
        }

        $args = [];

        foreach (is_array($value['args'] ?? null) ? $value['args'] : [] as $stored) {
            $arg = is_array($stored) ? self::fromArray($stored, $depth + 1) : $stored;

            if (! is_string($arg) && ! is_int($arg) && ! is_float($arg) && ! $arg instanceof self) {
                return null;
            }

            $args[] = $arg;
        }

        $many = is_string($value['many'] ?? null) ? $value['many'] : null;
        $count = is_int($value['count'] ?? null) ? $value['count'] : null;

        // A stored template is not the source's: it must take exactly the values that are there, or it is not used
        if (count(self::placeholders($value['one'])) !== count($args) && $args !== []) {
            return null;
        }

        return new self($value['one'], $many, $count, $args);
    }

    /**
     * The values a template takes: which one (the first, the second...) and of what kind. A translation may put them in another order with
     * numbered placeholders (`%2$s … %1$s`), which Japanese or Turkish often need; it may not lose or invent one.
     *
     * Only the kinds the source uses (strings, whole and decimal numbers): a lone "%" in a sentence ("100 % des") is not one.
     *
     * @return list<string>
     */
    private static function placeholders(string $template): array
    {
        preg_match_all('/%(?:(\d+)\$)?(?:\.\d+)?([sdf])/', str_replace('%%', '', $template), $matches, PREG_SET_ORDER);
        $found = [];

        foreach ($matches as $index => $match) {
            $found[] = ($match[1] !== '' ? (int) $match[1] : $index + 1).':'.$match[2];
        }

        sort($found);

        return $found;
    }
}
