<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\I18n;

use Pollora\Hornet\Domain\I18n\Translator;

/**
 * The translations of the package, with the functions of WordPress, for the language of whoever is looking (or of the site, for a cron).
 *
 * The package is not in a plugin folder: WordPress does not find its translations by itself, so they are loaded here, the first time a text
 * is asked for, and again when the language changes. Never before `init`: WordPress warns about it, and the language is not settled.
 */
final class WpTranslator implements Translator
{
    private const string DOMAIN = 'pollora-hornet';

    /** The locale whose file is loaded for the domain, in this request: WordPress holds one at a time for it. */
    private static ?string $loaded = null;

    public function __construct(private readonly string $languages) {}

    public static function forPackage(): self
    {
        return new self(dirname(__DIR__, 4).'/languages');
    }

    public function translate(string $text): string
    {
        if (! function_exists('translate')) {
            return $text;
        }

        $this->load();

        return translate($text, self::DOMAIN);
    }

    public function plural(string $one, string $many, int $count): string
    {
        if (! function_exists('translate_nooped_plural')) {
            return $count === 1 ? $one : $many;
        }

        $this->load();

        // `_n()` takes literals for the extraction tools; here they come from the message, which the extraction has read where it was written
        return translate_nooped_plural(['singular' => $one, 'plural' => $many, 'domain' => self::DOMAIN, 'context' => null], $count, self::DOMAIN);
    }

    private function load(): void
    {
        if (! did_action('init') || ! function_exists('determine_locale')) {
            return;
        }

        $locale = determine_locale();

        if (self::$loaded === $locale) {
            return;
        }

        self::$loaded = $locale;
        $file = sprintf('%s/%s-%s.mo', $this->languages, self::DOMAIN, $locale);

        // The file of another language is let go first: loaded next to it, WordPress would go on answering with the older one. No locale
        // is given to load_textdomain(): the file answers for whatever locale WordPress is using, and ours is chosen right here.
        unload_textdomain(self::DOMAIN, true);

        // WordPress reads the .l10n.php next to the name it is given for the .mo, which does not have to exist
        if (is_file(substr($file, 0, -2).'l10n.php')) {
            load_textdomain(self::DOMAIN, $file);
        }
    }
}
