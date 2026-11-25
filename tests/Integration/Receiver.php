<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Integration;

/**
 * A throwaway HTTP server (`php -S`) that records what it is sent: the other end of a channel.
 */
final class Receiver
{
    /** @var resource|null */
    private $process;

    private readonly string $log;

    private function __construct(public readonly int $port)
    {
        $this->log = sys_get_temp_dir().'/hornet-receiver-'.$port.'.log';
    }

    public static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $receiver = new self($port);
        $receiver->forget();
        $receiver->process = proc_open(
            ['php', '-S', '127.0.0.1:'.$port, '-t', __DIR__.'/../Fixtures', __DIR__.'/../Fixtures/receiver.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['HORNET_RECEIVER_LOG' => $receiver->log, 'PATH' => (string) getenv('PATH')],
        );

        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if ($probe !== false) {
                fclose($probe);

                return $receiver;
            }

            usleep(100000);
        }

        throw new \RuntimeException('The test receiver did not start.');
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:'.$this->port.$path;
    }

    /**
     * @return list<array{path: string, method: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        if (! is_file($this->log)) {
            return [];
        }

        return array_map(static fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))));
    }

    public function reset(): void
    {
        $this->forget();
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->forget();
    }

    private function forget(): void
    {
        if (is_file($this->log)) {
            unlink($this->log);
        }
    }
}
