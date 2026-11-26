<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * Hornet's own rules for what injected JavaScript does in the content of a site. A `<script>` is never enough: embeds, analytics
 * and header plugins put it there on purpose. What is looked for is code that hides what it does, and code where none belongs.
 *
 * The rules only read; nothing is run, and nothing of the content is ever given back: the reasons are Hornet's own words.
 *
 * @internal
 */
final class ContentRules
{
    /** What an entry must hold to be read at all: the store asks its database for these, so that it hands over little. */
    public const array MARKS = ['<script', '<iframe', 'javascript:', 'fromCharCode', 'atob(', 'eval(', 'unescape(', 'onerror', 'onload'];

    private const int HOSTS = 10;

    /** One of these says enough on its own. */
    private const array STRONG = [
        '/\b(?:eval|Function)\s*\(\s*(?:window\.)?(?:atob|unescape|String\s*\.\s*fromCharCode)\s*\(/' => 'runs code that is decoded first',
        '/\bdocument\s*\.\s*write(?:ln)?\s*\(\s*(?:window\.)?(?:atob|unescape|String\s*\.\s*fromCharCode)\s*\(/' => 'writes decoded code into the page',
        '/\blocation(?:\s*\.\s*(?:href|replace|assign))?\s*(?:=|\()\s*(?:window\.)?(?:atob|String\s*\.\s*fromCharCode)\s*\(/' => 'sends the visitor to a hidden address',
        '/\.\s*src\s*=\s*(?:window\.)?(?:atob|String\s*\.\s*fromCharCode)\s*\(/' => 'loads a script from a hidden address',
    ];

    /** Code that is not malware does these now and then. */
    private const array WEAK = [
        '/fromCharCode\s*\(\s*(?:\d{1,5}\s*,\s*){12}/' => 'builds text from a long list of character codes',
        '/(?:\\\\x[0-9a-fA-F]{2}){24}/' => 'holds a long run of escaped characters',
        '/\batob\s*\(\s*["\'][A-Za-z0-9+\/]{200}/' => 'decodes a long hidden block',
        '/(?:\b_0x[0-9a-f]{4,}\b.{0,200}?){8}/s' => 'has names made unreadable by an obfuscator',
        '/<script\b[^>]{0,500}\bsrc\s*=\s*["\']?(?:https?:)?\/\/(?:\d{1,3}\.){3}\d{1,3}\b/i' => 'loads a script from a bare IP address',
    ];

    /**
     * @param  string  $text  the text of an entry, or its start and its end
     * @param  ContentKind  $kind  where it was read: code has no place at all in some of them
     * @return array{severity: Severity|null, reasons: list<string>, hosts: list<string>} no severity when nothing is worth saying
     */
    public static function look(string $text, ContentKind $kind): array
    {
        $strong = [];
        $weak = [];

        foreach (self::STRONG as $pattern => $reason) {
            if (preg_match($pattern, $text) === 1) {
                $strong[] = $reason;
            }
        }

        foreach (self::WEAK as $pattern => $reason) {
            if (preg_match($pattern, $text) === 1) {
                $weak[] = $reason;
            }
        }

        if (self::hasHiddenFrame($text)) {
            $weak[] = 'holds a hidden frame to another site';
        }

        $script = preg_match('/<script\b|<[a-z][^>]{0,500}\son(?:error|load|click|mouseover)\s*=|\b(?:href|src)\s*=\s*["\']?\s*javascript:(?!\s*(?:void\s*\(\s*0?\s*\)|;|["\']))/i', $text) === 1;
        $critical = false;

        if ($kind === ContentKind::SiteIdentity && ($script || stripos($text, 'javascript:') !== false)) {
            $critical = true;
            $strong[] = 'has a script in the name or the address of the site';
        } elseif ($kind === ContentKind::Comment && $script) {
            $strong[] = 'has a script in a comment';
        } elseif ($kind === ContentKind::Profile && stripos(ltrim($text), 'javascript:') === 0) {
            $strong[] = 'has a javascript: address in a profile';
        }

        $severity = match (true) {
            $critical => Severity::Critical,
            $strong !== [], count($weak) > 1 => Severity::High,
            $weak !== [] => Severity::Medium,
            default => null,
        };

        return ['severity' => $severity, 'reasons' => [...$strong, ...$weak], 'hosts' => self::hosts($text)];
    }

    /**
     * Every reason, as it is translated.
     *
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [
            Message::mark('runs code that is decoded first'),
            Message::mark('writes decoded code into the page'),
            Message::mark('sends the visitor to a hidden address'),
            Message::mark('loads a script from a hidden address'),
            Message::mark('builds text from a long list of character codes'),
            Message::mark('holds a long run of escaped characters'),
            Message::mark('decodes a long hidden block'),
            Message::mark('has names made unreadable by an obfuscator'),
            Message::mark('loads a script from a bare IP address'),
            Message::mark('holds a hidden frame to another site'),
            Message::mark('has a script in the name or the address of the site'),
            Message::mark('has a script in a comment'),
            Message::mark('has a javascript: address in a profile'),
        ];
    }

    /**
     * A frame nobody can see, to another site. Tag managers put one inside `<noscript>`: that one is left alone.
     */
    private static function hasHiddenFrame(string $text): bool
    {
        if (stripos($text, '<iframe') === false) {
            return false;
        }

        $visible = (string) preg_replace('/<noscript\b.*?<\/noscript>/is', '', $text);
        preg_match_all('/<iframe\b[^>]{0,2000}>/i', $visible, $frames);

        foreach ($frames[0] as $frame) {
            if (preg_match('/\bsrc\s*=\s*["\']?(?:https?:)?\/\//i', $frame) === 1
                && preg_match('/\b(?:width|height)\s*=\s*["\']?[01](?:px)?["\'\s\/>]|display\s*:\s*none|visibility\s*:\s*hidden/i', $frame) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hosts the scripts are loaded from. Only what reads as a host name is kept: the rest of an address is the author's text.
     *
     * @return list<string>
     */
    private static function hosts(string $text): array
    {
        preg_match_all('/<script\b[^>]{0,500}\bsrc\s*=\s*["\']?(?:https?:)?\/\/([A-Za-z0-9.-]{1,100})(?=[\/"\'\s?#>:])/i', $text, $found);
        $hosts = array_filter(array_map(strtolower(...), $found[1]), static fn (string $host): bool => preg_match('/^(?:[a-z0-9-]{1,63}\.)+[a-z]{2,24}$/D', $host) === 1);

        return array_slice(array_values(array_unique($hosts)), 0, self::HOSTS);
    }
}
