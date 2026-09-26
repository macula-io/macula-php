<?php

declare(strict_types=1);

namespace Macula\Tests;

/**
 * tests/fixtures/provider.php, run for one test: a provider of `kind` on one
 * of the test stations, admitted by the org when asked.
 */
final class ProviderProcess
{
    public readonly string $nodeId;
    public readonly string $procedure;

    /** @param resource $process @param array<int, resource> $pipes */
    private function __construct(private $process, private array $pipes, TestStations $env, bool $admitted)
    {
        $this->nodeId = $this->line();
        if ($admitted) {
            $env->admit($this->nodeId);
            fwrite($this->pipes[0], "go\n");
            fflush($this->pipes[0]);
        }
        $this->procedure = $this->line();
    }

    public static function start(TestStations $env, string $kind, int $station, bool $admitted, bool $trusted = true): self
    {
        $s = $env->stations[$station];
        $config = json_encode([
            'seed' => ['host' => $s['host'], 'port' => $s['port'], 'node_id' => $s['node_id']],
            'realm_id' => $env->realmId,
            'realm_key' => $trusted ? $env->realmKey : null,
            'org' => $env->org,
            'kind' => $kind,
            'admitted' => $admitted,
        ], JSON_THROW_ON_ERROR);
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/provider.php', $config],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('the provider did not start');
        }
        return new self($process, $pipes, $env, $admitted);
    }

    /** Ends the provider: it withdraws its procedure and closes its pool. */
    public function stop(): void
    {
        fclose($this->pipes[0]);
        stream_get_contents($this->pipes[1]);
        fclose($this->pipes[1]);
        proc_close($this->process);
    }

    private function line(): string
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new \RuntimeException('the provider ended before it was serving');
        }
        return rtrim($line, "\n");
    }
}
