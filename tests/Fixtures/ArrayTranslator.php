<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\I18n\Translator;

final readonly class ArrayTranslator implements Translator
{
    /**
     * @param  array<string, string|list<string>>  $texts
     */
    public function __construct(private array $texts = []) {}

    public function translate(string $text): string
    {
        $found = $this->texts[$text] ?? $text;

        return is_array($found) ? $found[0] : $found;
    }

    public function plural(string $one, string $many, int $count): string
    {
        $forms = $this->texts[$one] ?? null;

        if (! is_array($forms)) {
            return $count === 1 ? $one : $many;
        }

        return $forms[$count === 1 ? 0 : 1];
    }
}
