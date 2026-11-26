<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\ContentKind;
use Pollora\Hornet\Domain\Scan\ContentRules;

function contentVerdict(string $text, ContentKind $kind = ContentKind::Post): array
{
    return ContentRules::look($text, $kind);
}

it('says nothing of the scripts a site usually holds', function (string $text): void {
    expect(contentVerdict($text)['severity'])->toBeNull();
})->with([
    'analytics' => ['<script async src="https://www.googletagmanager.com/gtag/js?id=G-X"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());</script>'],
    'a tag manager and its hidden frame in noscript' => ['<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>'],
    'a video' => ['<iframe width="560" height="315" src="https://www.youtube.com/embed/x" title="v"></iframe>'],
    'a button' => ['<a href="javascript:void(0)" class="open">Open</a><a href="javascript:;">x</a>'],
    'an eval of its own' => ['<script>var n = eval("1+1"); var s = atob("aGVsbG8=");</script>'],
    'a few character codes' => ['<script>String.fromCharCode(72,105)</script>'],
    'text that talks of code' => ['To decode, call atob( on the value, then eval( is a bad idea.'],
]);

it('reports code that hides what it does, as high', function (string $text, string $reason): void {
    expect(contentVerdict($text)['severity'])->toBe(Severity::High)->and(contentVerdict($text)['reasons'])->toContain($reason);
})->with([
    'eval of decoded' => ['<script>eval(atob("ZG9jdW1lbnQ="))</script>', 'runs code that is decoded first'],
    'a tag in capitals' => ['<SCRIPT>eval(String.fromCharCode(1))</SCRIPT>', 'runs code that is decoded first'],
    'written' => ['<script>document.write(unescape("%3Cscript"))</script>', 'writes decoded code into the page'],
    'redirect' => ['<script>window.location.href = atob("aHR0cDovL3g=")</script>', 'sends the visitor to a hidden address'],
    'a hidden source' => ['<script>var s=document.createElement("script");s.src=atob("eA==")</script>', 'loads a script from a hidden address'],
]);

it('reports one weak sign as medium, and two as high', function (): void {
    $codes = '<script>String.fromCharCode('.implode(',', range(100, 120)).')</script>';
    $ip = '<script src="//185.10.20.30/a.js"></script>';

    expect(contentVerdict($codes)['severity'])->toBe(Severity::Medium)
        ->and(contentVerdict($ip)['severity'])->toBe(Severity::Medium)
        ->and(contentVerdict($codes.$ip)['severity'])->toBe(Severity::High)
        ->and(contentVerdict('<script>var a="'.str_repeat('\x41', 30).'"</script>')['severity'])->toBe(Severity::Medium)
        ->and(contentVerdict('<iframe src="https://evil.test/x" width="0" height="0"></iframe>')['severity'])->toBe(Severity::Medium)
        ->and(contentVerdict('<script>'.str_repeat('var _0x1a2b=_0x3c4d[_0x5e6f];', 5).'</script>')['severity'])->toBe(Severity::Medium);
});

it('has no tolerance where no code belongs', function (): void {
    expect(contentVerdict('<script src="https://example.com/a.js"></script>', ContentKind::SiteIdentity)['severity'])->toBe(Severity::Critical)
        ->and(contentVerdict('Hello <script>alert(1)</script>', ContentKind::Comment)['severity'])->toBe(Severity::High)
        ->and(contentVerdict('<img src=x onerror=alert(1)>', ContentKind::Comment)['severity'])->toBe(Severity::High)
        ->and(contentVerdict('<a href="javascript:alert(1)">x</a>', ContentKind::Comment)['severity'])->toBe(Severity::High)
        ->and(contentVerdict('I like javascript: it is fun', ContentKind::Comment)['severity'])->toBeNull()
        ->and(contentVerdict('javascript:alert(document.cookie)', ContentKind::Profile)['severity'])->toBe(Severity::High);
});

it('keeps of an address only what reads as a host name', function (): void {
    $hosts = contentVerdict('<script src="https://cdn.Example.com/a.js?x=<b>"></script><script src="//stats.example.org"></script><script src="https://a b/c"></script>')['hosts'];

    expect($hosts)->toBe(['cdn.example.com', 'stats.example.org']);
});

it('has a translation mark for every reason it can give', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Domain/Scan/ContentRules.php');
    preg_match_all("/(?:=> |\\[\\] = )'([a-z][^']+)'/", $source, $given);

    expect(array_diff($given[1], ContentRules::reasons()))->toBe([]);
});

it('stays fast on a very long text made to trip a pattern up', function (): void {
    $long = str_repeat('<script a="', 20000).str_repeat('_0xabcd ', 20000).str_repeat('<iframe ', 5000);
    $start = microtime(true);
    contentVerdict($long);

    expect(microtime(true) - $start)->toBeLessThan(2.0)->and(preg_last_error())->toBe(PREG_NO_ERROR);
});
