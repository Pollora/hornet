<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

use Pollora\Hornet\Domain\Audit\Severity;

/**
 * One thing worth telling someone about.
 *
 * The `key` says what the alert is about: the same key twice is the same news, and a channel hears it once per cooldown.
 * The text comes from the site and from Hornet's own findings: a channel treats it as data and never as markup.
 */
final readonly class Alert
{
    /**
     * @param  list<string>  $details
     */
    public function __construct(
        public string $key,
        public Severity $severity,
        public string $title,
        public array $details = [],
        public ?string $fix = null,
    ) {}

    /**
     * @return array{key: string, severity: string, title: string, details: list<string>, fix: ?string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'severity' => $this->severity->value, 'title' => $this->title, 'details' => $this->details, 'fix' => $this->fix];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['key'] ?? null) ? $data['key'] : '',
            Severity::tryFrom(is_string($data['severity'] ?? null) ? $data['severity'] : '') ?? Severity::Info,
            is_string($data['title'] ?? null) ? $data['title'] : '',
            array_values(array_filter(is_array($data['details'] ?? null) ? $data['details'] : [], is_string(...))),
            is_string($data['fix'] ?? null) ? $data['fix'] : null,
        );
    }
}
