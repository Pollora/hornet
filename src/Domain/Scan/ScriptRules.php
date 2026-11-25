<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * Hornet's own rules for a PHP source that does what a web shell does. They are read from the tokens of the file
 * (`token_get_all`, in PHP itself: no extension, nothing to install), not from a list of known malware: the rules
 * of the known lists are under licences that a MIT package cannot take, and a list is always a step behind.
 *
 * No rule is enough alone: code that is not malware uses `eval`, `base64_decode` or a variable function now and then.
 * Points add up, and a file is reported from {@see ScriptScore::SUSPICIOUS}. The rules only ever read; they never run the code.
 */
final class ScriptRules
{
    private const array EXECUTORS = ['system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec'];

    private const array DECODERS = ['base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'convert_uudecode', 'hex2bin'];

    private const array INPUT = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES'];

    /** A blob this long, with no space in it, is not text a person wrote. */
    private const int BLOB = 2000;

    public static function score(string $source): ScriptScore
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : null;

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
        }

        $eval = false;
        $backtick = false;
        $blob = false;
        $regex = false;
        $varCall = false;
        $varVar = false;
        $input = false;
        $executors = [];
        $decoders = [];

        foreach ($tokens as $i => [$id, $text]) {
            $next = $tokens[$i + 1] ?? [null, ''];
            $before = $tokens[$i - 1][0] ?? null;
            $called = $next[1] === '(' && $before !== T_OBJECT_OPERATOR && $before !== T_DOUBLE_COLON && $before !== T_FUNCTION;
            $name = strtolower($text);

            if ($id === T_EVAL) {
                $eval = true;
            } elseif ($id === T_STRING && $called && in_array($name, self::EXECUTORS, true)) {
                $executors[$name] = true;
            } elseif ($id === T_STRING && $called && in_array($name, self::DECODERS, true)) {
                $decoders[$name] = true;
            } elseif ($id === T_STRING && $called && $name === 'preg_replace' && self::hasEvalModifier($tokens[$i + 2] ?? [null, ''])) {
                $regex = true;
            } elseif ($id === T_VARIABLE) {
                $input = $input || in_array($text, self::INPUT, true);
                $varCall = $varCall || $next[1] === '(';
            } elseif ($id === null && $text === '`') {
                $backtick = true;
            } elseif ($id === null && $text === '$' && ($next[0] === T_VARIABLE || $next[1] === '$')) {
                $varVar = true;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING && self::isBlob($text)) {
                $blob = true;
            }
        }

        $score = 0;
        $reasons = [];
        $add = static function (int $points, string $reason) use (&$score, &$reasons): void {
            $score += $points;
            $reasons[] = $reason;
        };

        if ($eval) {
            $add(4, 'eval()');
        }

        if ($executors !== []) {
            $add(3, 'runs a command ('.implode(', ', array_keys($executors)).')');
        }

        if ($backtick) {
            $add(3, 'runs a command (backticks)');
        }

        if ($regex) {
            $add(3, 'preg_replace with the /e modifier');
        }

        if ($decoders !== []) {
            $add(min(count($decoders), 3), 'decodes hidden data ('.implode(', ', array_keys($decoders)).')');
        }

        if ($blob) {
            $add(2, 'a very long string with no space in it');
        }

        if ($varVar) {
            $add(1, 'variable variables');
        }

        if ($varCall) {
            $add(1, 'calls a function named by a variable');
        }

        // What a visitor sends reaches something that runs it: the signature of a web shell
        if ($input && ($eval || $executors !== [] || $backtick)) {
            $add(3, 'passes request input to it');
        }

        return new ScriptScore($score, $reasons);
    }

    /**
     * @param  array{int|null, string}  $token  the first argument of a preg_replace
     */
    private static function hasEvalModifier(array $token): bool
    {
        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING || strlen($token[1]) < 4) {
            return false;
        }

        $pattern = substr($token[1], 1, -1);
        $delimiter = $pattern[0] ?? '';
        $end = strrpos($pattern, $delimiter);

        return $end !== false && $end > 0 && str_contains(substr($pattern, $end + 1), 'e');
    }

    private static function isBlob(string $literal): bool
    {
        return strlen($literal) >= self::BLOB && preg_match('/\s/', $literal) !== 1 && preg_match('/^.[A-Za-z0-9+\/=_-]+.$/sD', $literal) === 1;
    }
}
