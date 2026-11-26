<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\I18n;

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;

/**
 * The words of a finding in the language of someone; the finding itself is not changed.
 */
final class FindingTranslator
{
    public static function summary(Finding $finding, ?Translator $translator): string
    {
        return $finding->summaryMessage()->render($translator);
    }

    /**
     * @return list<string>
     */
    public static function details(Finding $finding, ?Translator $translator): array
    {
        return array_map(static fn (Message $line): string => $line->render($translator), $finding->detailMessages());
    }

    public static function fix(Finding $finding, ?Translator $translator): ?string
    {
        return $finding->fixMessage()?->render($translator);
    }
}
