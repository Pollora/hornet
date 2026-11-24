<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Infrastructure\WpCli\Output;
use RuntimeException;

/**
 * Collects what a command says. `error()` throws, as WP-CLI exits.
 */
final class RecordingOutput implements Output
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $successes = [];

    /** @var list<array<string, string>> */
    public array $rows = [];

    public string $format = '';

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function success(string $message): void
    {
        $this->successes[] = $message;
    }

    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function error(string $message): void
    {
        throw new RuntimeException($message);
    }

    public function items(array $rows, array $fields, string $format): void
    {
        $this->rows = $rows;
        $this->format = $format;
    }
}
