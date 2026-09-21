<?php

declare(strict_types=1);

namespace Linkado\Laravel\Tests\Support\Attribution;

use RuntimeException;

final class ConcurrentWorker
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    public readonly int $session;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start attribution worker.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $this->release($options);
        $this->session = $this->await('ready')['session'];
    }

    /** @param array<string, mixed> $command */
    public function release(array $command = []): void
    {
        fwrite($this->pipes[0], json_encode($command, JSON_THROW_ON_ERROR)."\n");
        fflush($this->pipes[0]);
    }

    /** @return array<string, mixed> */
    public function await(string $stage): array
    {
        $read = [$this->pipes[1]];
        $write = $except = [];

        if (stream_select($read, $write, $except, 15) !== 1) {
            throw new RuntimeException('Attribution worker barrier timed out: '.$stage);
        }
        $line = fgets($this->pipes[1]);
        $result = json_decode($line === false ? '{}' : $line, true, flags: JSON_THROW_ON_ERROR);

        if (($result['stage'] ?? null) !== $stage) {
            throw new RuntimeException('Unexpected worker response: '.json_encode($result, JSON_THROW_ON_ERROR));
        }

        return $result;
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
