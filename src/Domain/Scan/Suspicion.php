<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * A file that the scan would not leave unsaid, and why.
 */
final readonly class Suspicion
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $path,
        public Severity $severity,
        public array $reasons,
    ) {}

    public function line(): string
    {
        return sprintf('%s: %s', $this->path, implode('; ', $this->reasons));
    }

    /**
     * The reasons of {@see line()} as messages. A reason is stored as its English text, so what it was made of is read back from it;
     * the parts and what separates them are given as one flat list, to be joined with nothing.
     *
     * @return list<Message>
     */
    public function reasonMessages(): array
    {
        $parts = [];

        foreach ($this->reasons as $index => $reason) {
            if ($index > 0) {
                $parts[] = Message::raw('; ');
            }

            $parts = [...$parts, ...self::read($reason)];
        }

        return $parts;
    }

    /**
     * @return list<Message>
     */
    private static function read(string $reason): array
    {
        if (preg_match('/^reads like a web shell \(score (\d+)\): (.+)$/D', $reason, $shell) === 1) {
            $parts = [Message::of('reads like a web shell (score %d):', (int) $shell[1])];

            // The commas between parentheses belong to a reason: `runs a command (system, exec)`
            foreach (preg_split('/, (?![^(]*\))/', $shell[2]) ?: [] as $index => $one) {
                $parts[] = Message::raw($index === 0 ? ' ' : ', ');
                $parts[] = self::rule($one);
            }

            return $parts;
        }

        return [self::rule($reason)];
    }

    /**
     * One reason of the closed lists of {@see ScriptRules} and {@see FileInspector}, with the names of functions it may hold.
     */
    private static function rule(string $reason): Message
    {
        // Said as it is: what is between its parentheses is a word, not the name of a function
        if ($reason === 'runs a command (backticks)') {
            return Message::known($reason);
        }

        if (preg_match('/^runs a command \((.+)\)$/D', $reason, $command) === 1) {
            return Message::of('runs a command (%s)', $command[1]);
        }

        if (preg_match('/^decodes hidden data \((.+)\)$/D', $reason, $decoded) === 1) {
            return Message::of('decodes hidden data (%s)', $decoded[1]);
        }

        return Message::known($reason);
    }

    /**
     * @return array{path: string, severity: string, reasons: list<string>}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'severity' => $this->severity->value, 'reasons' => $this->reasons];
    }

    public static function fromArray(mixed $value): ?self
    {
        $severity = is_array($value) && is_string($value['severity'] ?? null) ? Severity::tryFrom($value['severity']) : null;

        if (! is_array($value) || ! is_string($value['path'] ?? null) || ! $severity instanceof Severity || ! is_array($value['reasons'] ?? null)) {
            return null;
        }

        return new self($value['path'], $severity, array_values(array_filter($value['reasons'], is_string(...))));
    }
}
