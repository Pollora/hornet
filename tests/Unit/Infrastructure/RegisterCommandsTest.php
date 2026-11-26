<?php

declare(strict_types=1);

/*
 * A classic install requires vendor/autoload.php at the top of wp-config.php, as the README says: register.php then runs before
 * any WordPress function exists. A call made there ended every WP-CLI command with a fatal error.
 */
it('declares the commands without a single WordPress function, as when wp-config.php requires the autoloader', function (): void {
    $script = <<<'PHP'
    define('WP_CLI', true);
    class WP_CLI { public static array $commands = []; public static function add_command(string $name, mixed $command): void { self::$commands[] = $name; } }
    require getcwd().'/vendor/autoload.php';
    echo implode(',', WP_CLI::$commands);
    PHP;

    exec(sprintf('cd %s && %s -r %s 2>&1', escapeshellarg(dirname(__DIR__, 3)), escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $code);

    expect($code)->toBe(0)->and(implode("\n", $output))->toBe('hornet,hornet notify,hornet scan,hornet journal,hornet server-config,hornet unlock');
});
