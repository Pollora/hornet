<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WpCli;

/**
 * What a command says to the person who ran it: WP-CLI's output in WordPress, a fake in tests.
 */
interface Output
{
    public function line(string $message): void;

    public function success(string $message): void;

    public function warning(string $message): void;

    /** Prints the message and ends the command with a failing exit code. */
    public function error(string $message): void;

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $fields
     * @param  string  $format  table, json, csv or yaml
     */
    public function items(array $rows, array $fields, string $format): void;
}
