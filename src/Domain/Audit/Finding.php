<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * What one check found: a verdict, a sentence, the facts that prove it, and what to do.
 *
 * A finding never carries a secret: a salt, a password or a key is named, never copied.
 */
final readonly class Finding
{
    /**
     * @param  list<string>  $details
     */
    private function __construct(
        public string $checkId,
        public Outcome $outcome,
        public Severity $severity,
        public string $summary,
        public array $details = [],
        public ?string $fix = null,
        public ?string $ignoredReason = null,
    ) {}

    /**
     * @param  list<string>  $details
     */
    public static function pass(string $checkId, string $summary, array $details = []): self
    {
        return new self($checkId, Outcome::Pass, Severity::Info, $summary, $details);
    }

    /**
     * @param  list<string>  $details
     */
    public static function fail(string $checkId, Severity $severity, string $summary, array $details = [], ?string $fix = null): self
    {
        return new self($checkId, Outcome::Fail, $severity, $summary, $details, $fix);
    }

    public static function unknown(string $checkId, string $reason): self
    {
        return new self($checkId, Outcome::Unknown, Severity::Info, $reason);
    }

    /**
     * The same finding, set aside on purpose by someone who wrote why.
     */
    public function ignoredFor(string $reason): self
    {
        return new self($this->checkId, $this->outcome, $this->severity, $this->summary, $this->details, $this->fix, $reason);
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
}
