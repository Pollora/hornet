<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Scan\ScriptRules;

it('reads request input written between braces as the input it is', function (string $source, string $reason): void {
    $result = ScriptRules::score('<?php '.$source);

    expect($result->reasons)->toContain($reason)->toContain('variable variables');
})->with([
    'handed to a command' => ['system(${"_GET"}["c"]);', 'passes request input to it'],
    'evaluated' => ["eval(\${'_POST'}['x']);", 'passes request input to it'],
    'naming the function' => ['${"_POST"}["a"]($b);', 'calls a function whose name is request input'],
    'naming a file' => ['include ${"_REQUEST"}["f"];', 'includes a file named by request input'],
]);

it('does not take another name between braces for request input', function (): void {
    $result = ScriptRules::score('<?php system(${"command"});');

    expect($result->reasons)->not->toContain('passes request input to it')->toContain('variable variables');
});
