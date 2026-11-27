<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Infrastructure\WpCli\Output;

/**
 * An output whose `error()` does not end the command, unlike WP-CLI's and {@see RecordingOutput}: a command that has
 * refused must still go no further.
 */
final class QuietOutput implements Output
{
    /** @var list<array{string, string}> what was said, as the kind and the message */
    public array $said = [];

    public function line(string $message): void
    {
        $this->said[] = ['line', $message];
    }

    public function success(string $message): void
    {
        $this->said[] = ['success', $message];
    }

    public function warning(string $message): void
    {
        $this->said[] = ['warning', $message];
    }

    public function error(string $message): void
    {
        $this->said[] = ['error', $message];
    }

    public function items(array $rows, array $fields, string $format): void
    {
        $this->said[] = ['items', $format];
    }
}
