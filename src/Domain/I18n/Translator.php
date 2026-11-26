<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\I18n;

/**
 * Says a text of the source in the language of someone.
 */
interface Translator
{
    public function translate(string $text): string;

    public function plural(string $one, string $many, int $count): string;
}
