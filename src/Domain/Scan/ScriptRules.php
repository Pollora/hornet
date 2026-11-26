<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\I18n\Message;

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

    /** Functions that call what they are given: handed request input as the callee, they run it. */
    private const array CALLERS = ['call_user_func', 'call_user_func_array', 'array_map', 'array_filter', 'array_walk', 'usort', 'uasort', 'uksort', 'create_function', 'forward_static_call', 'forward_static_call_array', 'register_shutdown_function'];

    /** What a callback named by a string runs as a command or as code. */
    private const array DANGEROUS_CALLBACKS = ['assert', 'system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'eval', 'create_function'];

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
        $inputCall = false;
        $inputCallback = false;
        $inputInclude = false;
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
            } elseif ($id === T_STRING && $called && ($name === 'assert' || in_array($name, self::CALLERS, true))) {
                $inputCallback = $inputCallback || self::handsInputToCallback($tokens, $i + 1);
            } elseif (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $inputInclude = $inputInclude || self::hasInput($tokens, $i + 1, self::end($tokens, $i + 1, ';'));
            } elseif ($id === T_VARIABLE) {
                $isInput = in_array($text, self::INPUT, true);
                $input = $input || $isInput;
                $varCall = $varCall || $next[1] === '(';
                // $_POST['a']($_POST['b']): the name of the function is what the visitor sent
                $inputCall = $inputCall || ($isInput && $next[1] === '[' && ($tokens[self::end($tokens, $i + 2, ']', '[') + 1][1] ?? '') === '(');
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

        if ($inputCall) {
            $add(7, Message::mark('calls a function whose name is request input'));
        }

        if ($inputCallback) {
            $add(7, Message::mark('hands request input to a callback that runs it'));
        }

        if ($inputInclude) {
            $add(7, Message::mark('includes a file named by request input'));
        }

        if ($eval) {
            $add(4, Message::mark('eval()'));
        }

        if ($executors !== []) {
            $add(3, sprintf(Message::mark('runs a command (%s)'), implode(', ', array_keys($executors))));
        }

        if ($backtick) {
            $add(3, Message::mark('runs a command (backticks)'));
        }

        if ($regex) {
            $add(3, Message::mark('preg_replace with the /e modifier'));
        }

        if ($decoders !== []) {
            $add(min(count($decoders), 3), sprintf(Message::mark('decodes hidden data (%s)'), implode(', ', array_keys($decoders))));
        }

        if ($blob) {
            $add(2, Message::mark('a very long string with no space in it'));
        }

        if ($varVar) {
            $add(1, Message::mark('variable variables'));
        }

        if ($varCall) {
            $add(1, Message::mark('calls a function named by a variable'));
        }

        // What a visitor sends reaches something that runs it: the signature of a web shell
        if ($input && ($eval || $executors !== [] || $backtick)) {
            $add(3, Message::mark('passes request input to it'));
        }

        return new ScriptScore($score, $reasons);
    }

    /**
     * Whether a source holds nothing that runs: PHP tags, comments and blank space. The question is put to the tokens of PHP, not to a pattern:
     * `<?php // x ?><?php include $_GET['f'];` is a comment and then code, whatever a regular expression thinks of the comment.
     */
    public static function isSilence(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                return false;
            }

            $silent = in_array($token[0], [T_OPEN_TAG, T_CLOSE_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true) || ($token[0] === T_INLINE_HTML && trim($token[1]) === '');

            if (! $silent) {
                return false;
            }
        }

        return true;
    }

    /**
     * The index of the token that closes what is open at `$from` (a `;`, or the `]` that matches a `[`), the end of the list when there is none.
     *
     * @param  list<array{int|null, string}>  $stream
     */
    private static function end(array $stream, int $from, string $close, ?string $open = null): int
    {
        $depth = 0;

        for ($i = $from; $i < count($stream); $i++) {
            if ($open !== null && $stream[$i][1] === $open) {
                $depth++;
            } elseif ($stream[$i][1] === $close) {
                if ($depth === 0) {
                    return $i;
                }

                $depth--;
            }
        }

        return count($stream);
    }

    /**
     * Whether request input appears between two tokens.
     *
     * @param  list<array{int|null, string}>  $tokens
     */
    private static function hasInput(array $tokens, int $from, int $to): bool
    {
        for ($i = $from; $i < $to && $i < count($tokens); $i++) {
            if ($tokens[$i][0] === T_VARIABLE && in_array($tokens[$i][1], self::INPUT, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `call_user_func($_GET['f'])`, `assert($_POST['x'])`, `array_map('assert', $_POST)`: the callee, or the string `assert` runs, is what the visitor sent,
     * or the callback is one that runs code. `array_map('trim', $_POST)` and `assert(is_int($_GET['id']))` are not that: the input is an argument to something that does not run it.
     *
     * @param  list<array{int|null, string}>  $tokens
     * @param  int  $open  the index of the `(` that opens the arguments
     */
    private static function handsInputToCallback(array $tokens, int $open): bool
    {
        $close = self::end($tokens, $open + 1, ')', '(');

        if (! self::hasInput($tokens, $open + 1, $close)) {
            return false;
        }

        $first = $tokens[$open + 1] ?? [null, ''];

        if ($first[0] === T_VARIABLE && in_array($first[1], self::INPUT, true)) {
            return true;
        }

        return $first[0] === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(trim($first[1], '\'"')), self::DANGEROUS_CALLBACKS, true);
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
