<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Scan\ScriptRules;

/*
 * What the PHP rules of the file scan see and what they leave alone, at the edges.
 */

it('reads a function written from the root like the plain name', function (string $source, string $reason): void {
    expect(ScriptRules::score($source)->reasons)->toContain($reason);
})->with([
    'a command' => ['<?php \system($_GET["c"]);', 'runs a command (system)'],
    'a decoder' => ['<?php \Base64_Decode($x);', 'decodes hidden data (base64_decode)'],
    'a caller' => ['<?php \call_user_func($_GET["f"], 1);', 'hands request input to a callback that runs it'],
    'assert' => ['<?php \assert($_POST["x"]);', 'hands request input to a callback that runs it'],
    'preg_replace' => ['<?php \preg_replace("/a/e", $_x, $s);', 'preg_replace with the /e modifier'],
    'a callback named from the root' => ['<?php call_user_func(\'\system\', $_GET["c"]);', 'hands request input to a callback that runs it'],
]);

it('leaves the function of a namespace alone', function (): void {
    expect(ScriptRules::score('<?php \Vendor\system("id"); \App\Support\base64_decode($x);')->reasons)->toBe([]);
});

it('names what it found, in a fixed order, and adds the points', function (): void {
    $result = ScriptRules::score('<?php SYSTEM($a); exec($b); Base64_Decode($c);');

    expect($result->reasons)->toBe(['runs a command (system, exec)', 'decodes hidden data (base64_decode)'])
        ->and($result->score)->toBe(4);
});

it('counts one point for each decoder, three at most', function (string $source, int $score): void {
    expect(ScriptRules::score('<?php '.$source)->score)->toBe($score);
})->with([
    'one' => ['base64_decode($a);', 1],
    'two' => ['base64_decode($a); gzinflate($a);', 2],
    'three' => ['base64_decode($a); gzinflate($a); str_rot13($a);', 3],
    'four' => ['base64_decode($a); gzinflate($a); str_rot13($a); hex2bin($a);', 3],
]);

it('sees a call with a comment between the name and its parenthesis', function (string $comment): void {
    expect(ScriptRules::score("<?php system {$comment} ('id');")->reasons)->toBe(['runs a command (system)']);
})->with(['a block comment' => '/* x */', 'a doc comment' => '/** x */']);

it('says nothing of a method, a declaration or a name that is not called', function (string $source): void {
    expect(ScriptRules::score('<?php '.$source)->reasons)->toBe([]);
})->with([
    'methods' => '$o->system("id"); Foo::exec("x"); function passthru($x) {}',
    'a decoder method' => '$o->base64_decode($a);',
    'a decoder name alone' => '$x = base64_decode;',
    'a preg_replace method' => '$o->preg_replace("/a/e", "b", $c);',
    'a caller method' => '$o->array_map($_GET["f"], $a);',
    'a variable that is not input, indexed then called' => '$handlers["a"]($x);',
    'a pattern with an e in it and no e flag' => 'preg_replace("/hello/i", "b", $c);',
    'a pattern that is only an e' => 'preg_replace("/e/", "b", $c);',
    'a pattern with no closing delimiter' => 'preg_replace("#hello", "b", $c);',
    'a pattern held in a variable' => 'preg_replace($xxeq, "b", $c);',
]);

it('sees the e flag of a short pattern', function (): void {
    expect(ScriptRules::score('<?php preg_replace("##e", $r, $s);')->reasons)->toBe(['preg_replace with the /e modifier']);
});

it('sees an included file named by request input, whatever the keyword', function (string $keyword): void {
    expect(ScriptRules::score("<?php echo 1; {$keyword} \$_GET['f'];")->reasons)->toBe(['includes a file named by request input']);
})->with(['include', 'include_once', 'require', 'require_once']);

it('does not take input of the next statement for the included file', function (): void {
    expect(ScriptRules::score('<?php include "a.php"; echo $_GET["x"];')->reasons)->toBe([]);
});

it('sees request input used as the name of a function, through nested brackets', function (string $source): void {
    expect(ScriptRules::score('<?php '.$source)->reasons)->toContain('calls a function whose name is request input');
})->with([
    'after an indexed value' => '$x = $y[0]; $_GET["f"]("a");',
    'a variable key' => '$_GET[$a[0]]("x");',
    'an array literal as the key' => '$_GET[[0][0]]("x");',
]);

it('says "variable variables" for each way of writing one', function (string $source): void {
    expect(ScriptRules::score('<?php '.$source)->reasons)->toContain('variable variables');
})->with(['$$a = 1;', '$${"a"} = 1;']);

it('follows the parentheses of a caller to the end of its own arguments', function (): void {
    expect(ScriptRules::score('<?php call_user_func("system", trim($a), $_GET["c"]);')->reasons)
        ->toContain('hands request input to a callback that runs it');
});

it('hands no input to a callback when the input is elsewhere', function (string $source): void {
    expect(ScriptRules::score('<?php '.$source)->reasons)->not->toContain('hands request input to a callback that runs it');
})->with([
    'in a later statement' => 'array_map("assert", $a); echo $_GET["x"];',
    'after nested parentheses' => 'call_user_func("system", trim($a)); echo $_GET["x"];',
    'no input at all' => 'array_map("system", $commands);',
    'a callee that is not input, with an input argument' => 'call_user_func($fn, $_GET["x"]);',
]);

it('sees a variable variable written between braces', function (): void {
    expect(ScriptRules::score('<?php ${"a"} = 1;')->reasons)->toBe(['variable variables']);
});

it('does not take a variable inside a string for a variable variable', function (): void {
    expect(ScriptRules::score('<?php echo "${a}";')->reasons)->toBe([]);
});

it('reports code that evaluates a variable named between braces', function (): void {
    expect(ScriptRules::score('<?php eval(${"_POST"}["x"]);')->isSuspicious())->toBeTrue();
});

it('reads the name of a dangerous callback whatever its case', function (): void {
    expect(ScriptRules::score('<?php call_user_func("SYSTEM", $_GET["c"]);')->reasons)->toContain('hands request input to a callback that runs it');
});

it('calls a string of 2000 characters a blob, not one of 1999', function (int $length, array $reasons): void {
    expect(ScriptRules::score('<?php $a = "'.str_repeat('a', $length - 2).'";')->reasons)->toBe($reasons);
})->with([
    '1999' => [1999, []],
    '2000' => [2000, ['a very long string with no space in it']],
]);

it('calls a file of tags and doc comments silent', function (): void {
    expect(ScriptRules::isSilence("<?php\n/** Silence is golden. */\n"))->toBeTrue();
});
