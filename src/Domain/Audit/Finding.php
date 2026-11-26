<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

use Pollora\Hornet\Domain\I18n\Message;

/**
 * What one check found: a verdict, a sentence, the facts that prove it, and what to do.
 *
 * A finding never carries a secret: a salt, a password or a key is named, never copied.
 */
final readonly class Finding
{
    /**
     * @param  list<string>  $details
     * @param  array{summary?: Message, details?: array<int, Message>, fix?: Message}  $messages  what can be said in another language
     */
    private function __construct(
        public string $checkId,
        public Outcome $outcome,
        public Severity $severity,
        public string $summary,
        public array $details = [],
        public ?string $fix = null,
        public ?string $ignoredReason = null,
        public array $messages = [],
    ) {}

    /**
     * Texts are given as a {@see Message}, which can be translated where they are shown; a plain string is left as it is.
     *
     * @param  list<string|Message>  $details
     */
    public static function pass(string $checkId, string|Message $summary, array $details = []): self
    {
        return self::make($checkId, Outcome::Pass, Severity::Info, $summary, $details, null);
    }

    /**
     * @param  list<string|Message>  $details
     */
    public static function fail(string $checkId, Severity $severity, string|Message $summary, array $details = [], string|Message|null $fix = null): self
    {
        return self::make($checkId, Outcome::Fail, $severity, $summary, $details, $fix);
    }

    public static function unknown(string $checkId, string|Message $reason): self
    {
        return self::make($checkId, Outcome::Unknown, Severity::Info, $reason, [], null);
    }

    /**
     * @param  list<string|Message>  $details
     */
    private static function make(string $checkId, Outcome $outcome, Severity $severity, string|Message $summary, array $details, string|Message|null $fix): self
    {
        $messages = [];
        $lines = [];

        if ($summary instanceof Message) {
            $messages['summary'] = $summary;
        }

        foreach (array_values($details) as $index => $line) {
            if ($line instanceof Message) {
                $messages['details'][$index] = $line;
            }

            $lines[] = $line instanceof Message ? $line->english() : $line;
        }

        if ($fix instanceof Message) {
            $messages['fix'] = $fix;
        }

        return new self($checkId, $outcome, $severity, $summary instanceof Message ? $summary->english() : $summary, $lines, $fix instanceof Message ? $fix->english() : $fix, null, $messages);
    }

    /**
     * The summary, a line of the details or the fix, as something that can be translated: the message when there is one, else the text as it is.
     */
    public function summaryMessage(): Message
    {
        return $this->messages['summary'] ?? Message::raw($this->summary);
    }

    /**
     * @return list<Message>
     */
    public function detailMessages(): array
    {
        return array_map(fn (int $index): Message => $this->messages['details'][$index] ?? Message::raw($this->details[$index]), array_keys($this->details));
    }

    public function fixMessage(): ?Message
    {
        return $this->fix === null ? null : ($this->messages['fix'] ?? Message::raw($this->fix));
    }

    /**
     * Reads what {@see toArray()} wrote (the last scan is stored that way). Null when it does not fit: a stored value may have been cut or edited.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $outcome = is_string($value['outcome'] ?? null) ? Outcome::tryFrom($value['outcome']) : null;
        $severity = is_string($value['severity'] ?? null) ? Severity::tryFrom($value['severity']) : null;
        $details = [];

        foreach (is_array($value['details'] ?? null) ? $value['details'] : [] as $line) {
            if (is_string($line)) {
                $details[] = $line;
            }
        }

        if (! is_string($value['check'] ?? null) || ! is_string($value['summary'] ?? null) || ! $outcome instanceof Outcome || ! $severity instanceof Severity) {
            return null;
        }

        $fix = is_string($value['fix'] ?? null) ? $value['fix'] : null;

        return new self($value['check'], $outcome, $severity, $value['summary'], $details, $fix, null, self::readMessages($value['i18n'] ?? null, $value['summary'], $details, $fix));
    }

    /**
     * The messages that were stored with a finding. One that does not say what the stored text says is left out: the text stays.
     *
     * @param  list<string>  $details
     * @return array{summary?: Message, details?: array<int, Message>, fix?: Message}
     */
    private static function readMessages(mixed $stored, string $summary, array $details, ?string $fix): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $read = static function (mixed $value, ?string $english): ?Message {
            $message = Message::fromArray($value);

            return $message instanceof Message && $message->english() === $english ? $message : null;
        };
        $messages = [];

        if (($message = $read($stored['summary'] ?? null, $summary)) instanceof Message) {
            $messages['summary'] = $message;
        }

        foreach (is_array($stored['details'] ?? null) ? $stored['details'] : [] as $index => $value) {
            if (is_int($index) && ($message = $read($value, $details[$index] ?? null)) instanceof Message) {
                $messages['details'][$index] = $message;
            }
        }

        if (($message = $read($stored['fix'] ?? null, $fix)) instanceof Message) {
            $messages['fix'] = $message;
        }

        return $messages;
    }

    /**
     * The same finding, set aside on purpose by someone who wrote why.
     */
    public function ignoredFor(string $reason): self
    {
        return new self($this->checkId, $this->outcome, $this->severity, $this->summary, $this->details, $this->fix, $reason, $this->messages);
    }

    public function isIgnored(): bool
    {
        return $this->ignoredReason !== null;
    }

    /**
     * Whether the finding asks for attention: failed, and not set aside.
     */
    public function isOpen(): bool
    {
        return $this->outcome === Outcome::Fail && ! $this->isIgnored();
    }

    /**
     * @return array{check: string, outcome: string, severity: string, summary: string, details: list<string>, fix: ?string, ignored: ?string}
     */
    public function toArray(): array
    {
        return [
            'check' => $this->checkId,
            'outcome' => $this->outcome->value,
            'severity' => $this->severity->value,
            'summary' => $this->summary,
            'details' => $this->details,
            'fix' => $this->fix,
            'ignored' => $this->ignoredReason,
        ];
    }

    /**
     * What is stored: the same as {@see toArray()}, plus what lets it be translated later.
     *
     * @return array<string, mixed>
     */
    public function toStoredArray(): array
    {
        if ($this->messages === []) {
            return $this->toArray();
        }

        $i18n = [];

        foreach ($this->messages as $key => $value) {
            $i18n[$key] = $value instanceof Message ? $value->toArray() : array_map(static fn (Message $message): array => $message->toArray(), $value);
        }

        return [...$this->toArray(), 'i18n' => $i18n];
    }
}
