<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Notification;

use JsonSerializable;
use LogicException;

/**
 * A webhook URL, a token or a signing key.
 *
 * It prints as `[redacted]` everywhere (string cast, dump, JSON), and refuses to be serialized, so that it cannot reach
 * a log, an error message, a cache or a status screen by accident. The only way to read it is `reveal()`, at the moment of use.
 */
final readonly class Secret implements \Stringable, JsonSerializable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return '[redacted]';
    }

    public function jsonSerialize(): string
    {
        return '[redacted]';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A secret is never serialized.');
    }
}
