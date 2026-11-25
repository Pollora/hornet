<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Audit\Severity;

/**
 * Looks at one file of the content folder and says whether it would not be left unsaid. It reads; it never runs, moves or deletes anything.
 *
 * Signals, from the strongest: a script in the uploads (nothing there should run), a server setting that runs code before every
 * page or turns a picture into a script, a file named like a picture that a server may still run (`shell.php.jpg`), a hidden script,
 * and a PHP file whose code reads like a web shell (see {@see ScriptRules}).
 */
final readonly class FileInspector
{
    /** A PHP file is read up to this size: a web shell is small, and a larger file is a library. */
    private const int READ = 524288;

    /** What an `.htaccess` or a `.user.ini` says when it runs code that is not the site's. */
    private const string DANGEROUS_SETTING = '/^\s*(?:auto_prepend_file|auto_append_file|php_value\s+auto_(?:prepend|append)_file|(?:AddHandler|AddType|SetHandler)\b.*\bphp)/imD';

    /** What WordPress and the plugins put in a folder so that it cannot be listed: a comment, no code. */
    private const string SILENCE = '/^<\?php\s*(?:\/\/[^\n]*|\/\*.*?\*\/|#[^\n]*)?\s*(?:\?>)?\s*$/sD';

    /**
     * @param  string  $uploads  the uploads folder, relative to the content folder ('' when it is not inside it)
     */
    public function __construct(
        private FileSystem $content,
        private string $uploads,
    ) {}

    public function inspect(string $path): ?Suspicion
    {
        $name = basename($path);
        $inUploads = $this->uploads !== '' && str_starts_with($path, $this->uploads.'/');
        $script = CoreIntegrity::isScript($name);
        $reasons = [];
        $severity = Severity::Info;
        $flag = static function (Severity $level, string $reason) use (&$reasons, &$severity): void {
            $reasons[] = $reason;
            $severity = $level->atLeast($severity) ? $level : $severity;
        };

        if ($name === '.htaccess' || $name === '.user.ini') {
            $contents = $this->content->read($path, 65536) ?? '';

            if (preg_match(self::DANGEROUS_SETTING, $contents) === 1) {
                $flag($inUploads ? Severity::High : Severity::Medium, $name === '.htaccess' ? 'a server setting that runs PHP code or makes a file run as PHP' : 'a setting that runs a PHP file before or after every script');
            }
        }

        // `shell.php.jpg` is a picture to WordPress and a script to a server that is set to run any name that holds `.php`
        if (! $script && preg_match('/\.(?:php\d?|phtml|pht|phar)\.[A-Za-z0-9]{1,6}$/iD', $name) === 1) {
            $flag(Severity::Medium, 'a name with a PHP extension in the middle (shell.php.jpg): some servers run it');
        }

        if ($script) {
            $contents = $this->content->read($path, self::READ);

            if ($inUploads && ! ($contents !== null && preg_match(self::SILENCE, $contents) === 1)) {
                $flag(Severity::High, 'a PHP file in the uploads folder, where nothing should run');
            }

            if (str_starts_with($name, '.')) {
                $flag(Severity::Medium, 'a hidden PHP file');
            }

            $score = $contents !== null && ($this->content->size($path) ?? 0) <= self::READ ? ScriptRules::score($contents) : new ScriptScore(0);

            if ($score->isSuspicious()) {
                $flag($score->score >= ScriptScore::LIKELY_SHELL ? Severity::High : Severity::Medium, sprintf('reads like a web shell (score %d): %s', $score->score, implode(', ', $score->reasons)));
            }
        }

        return $reasons === [] ? null : new Suspicion($path, $severity, $reasons);
    }
}
