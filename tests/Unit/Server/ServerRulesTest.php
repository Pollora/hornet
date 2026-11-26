<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\ServerRulesException;
use Pollora\Hornet\Domain\Server\ServerRules;
use Pollora\Hornet\Infrastructure\WpCli\ServerConfigCommand;
use Pollora\Hornet\Tests\Fixtures\RecordingOutput;

it('refuses PHP from the uploads folder, dotfiles and files that are never public, on both servers', function (string $server): void {
    $rules = ServerRules::for($server, '/app/uploads');

    expect($rules)->toContain('/app/uploads')->toContain('phtml')->toContain('phar')
        ->toContain('well-known')->toContain('wp-config')->toContain('composer')->toContain('sql')
        ->not->toContain('X-Content-Type-Options');
})->with(['nginx', 'apache', 'htaccess']);

it('sets the headers that never change only when asked', function (string $server): void {
    expect(ServerRules::for($server, '/wp-content/uploads', true))->toContain('X-Content-Type-Options')->toContain('Referrer-Policy')->toContain('Permissions-Policy');
})->with(['nginx', 'apache', 'htaccess']);

it('gives an .htaccess nothing that Apache refuses there', function (): void {
    // <Location> and <LocationMatch> in an .htaccess answer 500 to every request of the site
    expect(ServerRules::for('htaccess', '/app/uploads', true))->toContain('RewriteEngine On')->toContain('%{REQUEST_URI}')
        ->not->toContain('<Location')->not->toContain('Require ');
});

it('refuses what is inside a dot folder, not only a file whose name starts with a dot', function (): void {
    // <FilesMatch> only sees the last part of the path: .git/config was served
    expect(ServerRules::for('apache', '/uploads'))->toContain('<LocationMatch "/\\.(?!well-known')->not->toContain('<FilesMatch "^\\.">');
});

it('treats a trailing slash as the same folder', function (): void {
    expect(ServerRules::for('nginx', '/wp-content/uploads/'))->toBe(ServerRules::for('nginx', '/wp-content/uploads'));
});

it('puts nothing in a configuration file that is not a plain path', function (string $path): void {
    expect(fn () => ServerRules::for('nginx', $path))->toThrow(ServerRulesException::class, 'not a path');
    expect(fn () => ServerRules::for('apache', $path))->toThrow(ServerRulesException::class, 'not a path');
})->with([
    'a relative path' => ['uploads'],
    'a parent' => ['/app/../etc'],
    'a brace that closes the block' => ['/app/uploads { return 200; } location / '],
    'a quote' => ['/app/uploads"'],
    'a newline' => ["/app/uploads\nInclude /etc/passwd"],
    'a semicolon' => ['/app;uploads'],
    'a dollar' => ['/app/$uploads'],
    'a space' => ['/app/my uploads'],
    'nothing' => [''],
    'the root' => ['/'],
    'too deep' => ['/a/b/c/d/e/f/g/h'],
]);

it('says which servers it knows', function (): void {
    expect(fn () => ServerRules::for('iis', '/uploads'))->toThrow(ServerRulesException::class, 'nginx, apache or htaccess');
});

it('prints the rules, and nothing else, through the command', function (): void {
    $output = new RecordingOutput;

    (new ServerConfigCommand(static fn (): string => '/wp-content/uploads', $output))(['nginx'], ['headers' => true]);

    expect(implode("\n", $output->lines))->toContain('location ^~ /wp-content/uploads/')->toContain('nosniff');
});

it('uses the folder it is given over the one of the site', function (): void {
    $output = new RecordingOutput;

    (new ServerConfigCommand(static fn (): string => '/wp-content/uploads', $output))(['apache'], ['uploads' => '/app/uploads']);

    expect(implode("\n", $output->lines))->toContain('^/app/uploads/')->not->toContain('wp-content');
});

it('stops with what is wrong when the folder or the server is not right (the output ends the command, as WP-CLI does)', function (array $args, array $assoc, string $message): void {
    $command = new ServerConfigCommand(static fn (): string => '/wp-content/uploads', new RecordingOutput);

    expect(fn () => $command($args, $assoc))->toThrow(RuntimeException::class, $message);
})->with([
    'a folder with a brace' => [['apache'], ['uploads' => '/app/{'], 'not a path'],
    'a server that is not known' => [['iis'], [], 'nginx, apache or htaccess'],
    'no server' => [[], [], 'nginx, apache or htaccess'],
]);
