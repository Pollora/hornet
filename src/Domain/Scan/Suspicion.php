<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Audit\Severity;

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
