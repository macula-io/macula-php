<?php

declare(strict_types=1);

namespace Macula\Tests;

/**
 * Runs macula-go's teststation at the tag in abi/MACULA_GO_REF (built to
 * build/teststation by `composer build`) for a test class: two in-process wire
 * 1.0 stations sharing a DHT and a test realm, driven over the helper's stdin.
 */
final class TestStations
{
    /** @var list<array{host: string, port: int, node_id: string}> */
    public readonly array $stations;
    public readonly string $realmId;
    public readonly string $realmName;
    public readonly string $realmKey;
    public readonly string $org;
    public readonly string $orgKeyId;

    /** @param resource $process @param array<int, resource> $pipes */
    private function __construct(private $process, private array $pipes)
    {
        $info = json_decode($this->line(), true, flags: JSON_THROW_ON_ERROR);
        $this->stations = $info['stations'];
        $this->realmId = $info['realm_id'];
        $this->realmName = $info['realm_name'];
        $this->realmKey = $info['realm_key'];
        $this->org = $info['org'];
        $this->orgKeyId = $info['org_key_id'];
    }

    public static function start(): self
    {
        $binary = __DIR__ . '/../build/teststation';
        if (!is_executable($binary)) {
            throw new \RuntimeException("{$binary} not found: run `composer build` first");
        }
        $process = proc_open([$binary, 'pq_pure'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('teststation did not start');
        }
        return new self($process, $pipes);
    }

    /** Station i as a seed, pinned by its node_id. */
    public function seed(int $i): \Macula\Seed
    {
        $s = $this->stations[$i];
        return new \Macula\Seed($s['host'], $s['port'], $s['node_id']);
    }

    /** @return array<string, string> the test realm's key, by realm id */
    public function trust(): array
    {
        return [$this->realmId => $this->realmKey];
    }

    /** The org delegates its procedures to the node. */
    public function admit(string $nodeId): void
    {
        $reply = $this->ask("admit {$nodeId}");
        if (!str_starts_with($reply, 'admitted')) {
            throw new \RuntimeException($reply);
        }
    }

    /**
     * The org's UCAN for the node: a grant over
     * `mri:org:<realm_name>/<org>` carrying `can` "group_keys", living an
     * hour. It is the grant a group subscription presents on every key pull,
     * and the only thing that admits a caller to a stand-in distributor's
     * `realm_member_required` policy.
     */
    public function grant(string $nodeId): string
    {
        $reply = $this->ask("grant {$nodeId}");
        if (!str_starts_with($reply, 'grant ')) {
            throw new \RuntimeException($reply);
        }
        return substr($reply, strlen('grant '));
    }

    /** How many streams the stations relay now. */
    public function relayed(): int
    {
        return (int) explode(' ', $this->ask('relayed'))[1];
    }

    public function stop(): void
    {
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private function ask(string $command): string
    {
        fwrite($this->pipes[0], $command . "\n");
        fflush($this->pipes[0]);
        return $this->line();
    }

    private function line(): string
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new \RuntimeException('teststation ended');
        }
        return rtrim($line, "\n");
    }
}
