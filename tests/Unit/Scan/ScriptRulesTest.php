<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Scan\ScriptRules;
use Pollora\Hornet\Domain\Scan\ScriptScore;

it('flags code that runs what a visitor sends', function (string $source, int $score): void {
    $result = ScriptRules::score($source);

    expect($result->score)->toBe($score)->and($result->isSuspicious())->toBeTrue();
})->with([
    'eval of the request' => ['<?php eval($_POST["x"]);', 7],
    'a command from the request' => ['<?php system($_GET["c"]);', 6],
    'a command in backticks from the request' => ['<?php echo `{$_GET["c"]}`;', 6],
    'eval of a decoded blob' => ['<?php eval(base64_decode("ZWNobyAxOw=="));', 5],
    'eval of a deflated blob' => ['<?php eval(gzinflate(base64_decode("x")));', 6],
    'the function is named by the request' => ['<?php $_POST["a"]($_POST["b"]);', 7],
    'the function is named by the request, the usual way' => ['<?php @$_REQUEST[\'f\']($_REQUEST[\'c\']);', 7],
    'a callback named by the request' => ['<?php call_user_func($_GET["f"], "id");', 7],
    'a callback that runs code, given the request' => ['<?php array_map("assert", $_POST);', 7],
    'assert of the request' => ['<?php assert($_POST["x"]);', 7],
    'a file named by the request' => ['<?php include $_GET["page"];', 7],
    'a file named by the request, in a string' => ['<?php require_once "pages/".$_GET["page"].".php";', 7],
    'preg_replace with the e modifier, and a command, from the request' => ['<?php preg_replace("/x/e", $_GET["c"], "x"); passthru($a);', 9],
]);

it('counts the e modifier of preg_replace by itself, and not another modifier', function (): void {
    expect(ScriptRules::score('<?php preg_replace("/x/e", $a, "x");')->score)->toBe(3)
        ->and(ScriptRules::score('<?php preg_replace("/x/i", $a, "x");')->score)->toBe(0)
        ->and(ScriptRules::score('<?php preg_replace($a, $b, "x");')->score)->toBe(0);
});

it('says why', function (): void {
    expect(ScriptRules::score('<?php eval($_POST["x"]);')->reasons)->toBe(['eval()', 'passes request input to it']);
});

it('leaves ordinary code alone, even when it uses what a shell uses', function (string $source): void {
    expect(ScriptRules::score($source)->isSuspicious())->toBeFalse();
})->with([
    'a callback from a variable and the query' => ['<?php $callback = $args["cb"]; $page = $_GET["page"]; echo $callback($page);'],
    'a decoder on its own' => ['<?php $data = base64_decode($_POST["image"]); save($data);'],
    'a command that is not from the request' => ['<?php exec("ls -l");'],
    'an eval that is only a word in a comment' => ["<?php\n// eval(\$_POST['x']) is what a shell does\n/* system(\$_GET['c']) */\necho 1;"],
    'a method named exec, and one declared so' => ['<?php class A { function exec() {} } $a->exec($_GET["x"]); A::system($_POST["y"]); eval_not($x);'],
    'a string that says eval' => ['<?php echo "eval($_POST) and system(x)";'],
    'a callback that is not given the request first' => ['<?php $clean = array_map("trim", $_POST); call_user_func("render", $_GET["id"]);'],
    'assert of something else' => ['<?php assert($x > 0); assert(is_int($_GET["id"]) === false || true);'],
    'a file that is not named by the request' => ['<?php include __DIR__."/a.php"; require_once $path; echo $_GET["page"];'],
    'an index of the request that is not called' => ['<?php $f = $_POST["a"]; echo $_POST["b"][0];'],
    'plain HTML' => ['<html><body>Hello</body></html>'],
    'nothing' => [''],
]);

it('reads a long blob with no space in it as a hidden payload', function (): void {
    $result = ScriptRules::score('<?php $x = "'.str_repeat('QUJD', 600).'"; eval($x);');

    expect($result->score)->toBe(6)->and($result->reasons)->toContain('a very long string with no space in it');
});

it('does not take a long text for a payload', function (): void {
    expect(ScriptRules::score('<?php $x = "'.str_repeat('word and another ', 200).'";')->score)->toBe(0);
});

it('counts variable variables and calls named by a variable, a little', function (): void {
    expect(ScriptRules::score('<?php $$name = 1; $fn($x);')->score)->toBe(2);
});

it('never fails on a file that is not valid PHP', function (): void {
    expect(ScriptRules::score('<?php if ( { eval(')->score)->toBeInt()
        ->and(ScriptRules::score("\0\1\2 binary")->isSuspicious())->toBeFalse();
});

it('puts a number on the boundaries', function (): void {
    expect(ScriptScore::SUSPICIOUS)->toBe(5)->and(ScriptScore::LIKELY_SHELL)->toBe(7)->and((new ScriptScore(4))->isSuspicious())->toBeFalse();
});

it('knows a file that holds nothing that runs, by its tokens', function (string $source, bool $silence): void {
    expect(ScriptRules::isSilence($source))->toBe($silence);
})->with([
    ['<?php // Silence is golden.', true],
    ["<?php\n// Silence is golden.\n", true],
    ["<?php\n/* Silence is golden */\n?>\n", true],
    ['<?php # nothing', true],
    ["<?php\n", true],
    ['<?php // x ?>'."\n", true],
    // The comment of a line stops at the closing tag: what follows is code
    ['<?php // x ?><?php include $_GET["f"];', false],
    ["<?php // Silence is golden.\nsystem(\$_GET['c']);", false],
    ['<?php /* x */ echo 1;', false],
    ['<html>hello</html>', false],
    ["<?php ?>\n  \n", true],
]);
