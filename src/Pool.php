<?php

declare(strict_types=1);

namespace Macula;

/**
 * A node's pool of station links on the macula 12 mesh, as macula-go's pool
 * keeps it: every seed pinned by its node_id, the realms whose keys the node
 * trusts, and one identity for every link. Calls and streams reach a provider
 * by direct dial: its advertisements from the DHT, trusted only when the
 * realm's key authorizes them (or, in a node's own namespace `~<node_id>/`,
 * only when that node signed them), and the station it serves from dialed
 * pinned.
 *
 * Ids (realms, nodes, record keys) are taken as 64 hex characters or 32
 * bytes, and given back as hex. Every method blocks until it is done.
 */
final class Pool
{
    private bool $closed = false;

    private function __construct(private readonly int $handle)
    {
    }

    /**
     * Links the key's node to every seed, and returns once one link is up.
     *
     * @param list<Seed> $seeds
     * @param array<string, string> $realmTrust each realm's key as carried
     *   (hex), by realm id: an advertisement in a realm is trusted only when
     *   its authorization verifies against it, and a procedure is served only
     *   in a realm it names
     * @param int $timeoutMs how long to wait for a first link, 30 s when 0
     */
    public static function connect(
        NodeKey $key,
        array $seeds,
        array $realmTrust = [],
        int $replicationFactor = 0,
        int $maxDirectLinks = 0,
        int $respawnDelayMs = 0,
        int $timeoutMs = 0,
    ): self {
        $seedJson = array_map(fn (Seed $s) => [
            'host' => $s->host,
            'port' => $s->port,
            'node_id' => bin2hex(Wire::id32($s->nodeId, "a seed's nodeId")),
        ], $seeds);
        $trust = new \stdClass();
        foreach ($realmTrust as $realm => $realmKey) {
            $trust->{bin2hex(Wire::id32((string) $realm, 'a realm id'))} = $realmKey;
        }
        $opts = [
            'realm_trust' => $trust,
            'replication_factor' => $replicationFactor,
            'max_direct_links' => $maxDirectLinks,
            'respawn_delay_ms' => $respawnDelayMs,
            'timeout_ms' => $timeoutMs,
        ];
        $k = $key->live();
        return new self(Binding::call(fn ($err) => Binding::ffi()->macula_pool_connect($k,
            json_encode($seedJson, JSON_THROW_ON_ERROR), json_encode($opts, JSON_THROW_ON_ERROR), $err)));
    }

    /** The node_id the pool links as, as hex. */
    public function nodeId(): string
    {
        $h = $this->live();
        return Binding::id32Out(fn ($out, $err) => Binding::ffi()->macula_pool_node_id($h, $out, $err));
    }

    /** name in this node's own namespace, `~<node_id>/<name>`: a procedure it
     * serves with no org and no realm key, authorized by its advertisement's
     * signature alone, and that any node calls with no realm key pinned. */
    public function ownProcedure(string $name): string
    {
        return "~{$this->nodeId()}/{$name}";
    }

    /** Every link the pool holds. @return list<LinkStatus> */
    public function status(): array
    {
        $h = $this->live();
        $links = Wire::decode(Binding::takeString(Binding::call(fn ($err) => Binding::ffi()->macula_pool_status($h, $err))));
        return array_map(fn (array $l) => new LinkStatus($l['station'], $l['host'], $l['port'], $l['direct'], $l['up']),
            $links ?? []);
    }

    /**
     * Calls procedure in realm at a provider (any trusted one unless provider
     * names one) by direct dial, and returns its result. A provider's ERROR is
     * thrown as a ProviderError, a station's relay error as a RelayError.
     */
    public function call(
        string $realm,
        string $procedure,
        mixed $payload = new \stdClass(),
        ?string $provider = null,
        int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS,
        BytesOutput $bytes = BytesOutput::Hex,
    ): mixed {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        $provider32 = $provider === null ? null : Binding::buffer(Wire::id32($provider, 'provider'));
        $json = Wire::encode($payload);
        $result = Binding::call(fn ($err) => Binding::ffi()->macula_pool_call($h, $realm32, $procedure, $json, $provider32,
            $timeoutMs, $bytes->value, $err), Wire::callError(...));
        return Wire::decode(Binding::takeString($result));
    }

    /** The procedure's trusted providers, freshest first. @return list<Provider> */
    public function providers(string $realm, string $procedure, int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS): array
    {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        $found = Wire::decode(Binding::takeString(Binding::call(fn ($err) => Binding::ffi()->macula_pool_providers($h,
            $realm32, $procedure, $timeoutMs, $err))));
        return array_map(fn (array $p) => new Provider($p['node'], $p['station']), $found ?? []);
    }

    /** Publishes payload on topic in realm. Topics name a kind of fact; ids go
     * in the payload. */
    public function publish(string $realm, string $topic, mixed $payload, int $ttlMs = 0): void
    {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        $json = Wire::encode($payload);
        Binding::call(fn ($err) => Binding::ffi()->macula_pool_publish($h, $realm32, $topic, $json, $ttlMs, $err));
    }

    /** Subscribes to topic in realm. Each verified event is heard once, however
     * many links deliver it, and waits until Subscription::next takes it. */
    public function subscribe(string $realm, string $topic, BytesOutput $bytes = BytesOutput::Hex): Subscription
    {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        return new Subscription(Binding::call(fn ($err) => Binding::ffi()->macula_pool_subscribe($h, $realm32, $topic,
            $bytes->value, $err)));
    }

    /**
     * Serves procedure in realm. Its calls wait until Served::next or
     * Served::handle takes them, each answered by its deadline or for it with
     * an error. An org procedure needs the realm's key pinned and the org's
     * delegation to this node in the DHT; a procedure in this node's own
     * namespace (ownProcedure) needs neither, and another node's namespace is
     * refused.
     */
    public function serve(string $realm, string $procedure, BytesOutput $bytes = BytesOutput::Hex): Served
    {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        return new Served(Binding::call(fn ($err) => Binding::ffi()->macula_pool_serve($h, $realm32, $procedure,
            $bytes->value, $err)));
    }

    /** Serves procedure in realm as a stream of mode. Its sessions wait until
     * ServedStream::next or ServedStream::handle takes them. */
    public function serveStream(
        string $realm,
        string $procedure,
        StreamMode $mode,
        BytesOutput $bytes = BytesOutput::Hex,
    ): ServedStream {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        return new ServedStream(Binding::call(fn ($err) => Binding::ffi()->macula_pool_serve_stream($h, $realm32,
            $procedure, $mode->value, $bytes->value, $err)), $bytes);
    }

    /** Opens a stream of mode on procedure in realm at a provider, by direct
     * dial. A refusal arrives on its first recv(). */
    public function openStream(
        string $realm,
        string $procedure,
        StreamMode $mode,
        mixed $payload = new \stdClass(),
        ?string $provider = null,
        int $deadlineMs = 0,
        int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS,
        BytesOutput $bytes = BytesOutput::Hex,
    ): Stream {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        $provider32 = $provider === null ? null : Binding::buffer(Wire::id32($provider, 'provider'));
        $json = Wire::encode($payload);
        return new Stream(Binding::call(fn ($err) => Binding::ffi()->macula_pool_open_stream($h, $realm32, $procedure,
            $mode->value, $json, $provider32, $deadlineMs, $timeoutMs, $err), Wire::callError(...)), $bytes);
    }

    /** The verified record under key, or null when there is none. */
    public function findRecord(
        string $key,
        int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS,
        BytesOutput $bytes = BytesOutput::Hex,
    ): ?DhtRecord {
        $h = $this->live();
        $key32 = Binding::buffer(Wire::id32($key, 'key'));
        try {
            $found = Binding::call(fn ($err) => Binding::ffi()->macula_pool_find_record($h, $key32, $timeoutMs,
                $bytes->value, $err));
        } catch (MaculaException $e) {
            if ($e->getMessage() === 'not_found') {
                return null;
            }
            throw $e;
        }
        return DhtRecord::fromArray(Wire::decode(Binding::takeString($found)));
    }

    /** Every verified record under key, and how many did not verify. */
    public function findRecords(
        string $key,
        int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS,
        BytesOutput $bytes = BytesOutput::Hex,
    ): FoundRecords {
        $h = $this->live();
        $key32 = Binding::buffer(Wire::id32($key, 'key'));
        return FoundRecords::fromJson(Binding::takeString(Binding::call(fn ($err) =>
            Binding::ffi()->macula_pool_find_records($h, $key32, $timeoutMs, $bytes->value, $err))));
    }

    /** Every verified record of type the station holds, and how many did not
     * verify. */
    public function findRecordsByType(
        RecordType|int $type,
        int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS,
        BytesOutput $bytes = BytesOutput::Hex,
    ): FoundRecords {
        $h = $this->live();
        $t = $type instanceof RecordType ? $type->value : $type;
        return FoundRecords::fromJson(Binding::takeString(Binding::call(fn ($err) =>
            Binding::ffi()->macula_pool_find_records_by_type($h, $t, $timeoutMs, $bytes->value, $err))));
    }

    /** Puts a signed record's wire bytes in the DHT. */
    public function putRecord(string $wire, int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS): void
    {
        $h = $this->live();
        $buf = Binding::buffer($wire);
        Binding::call(fn ($err) => Binding::ffi()->macula_pool_put_record($h, $buf, strlen($wire), $timeoutMs, $err));
    }

    /**
     * Shares data in realm: this node keeps it, serves it on its own
     * `~<node_id>/content_v1` and announces it, renewing the announcement
     * until unshareContent or close. Data of at most 256 KiB is one raw block;
     * larger data a manifest over 256 KiB chunks, named name. Returns the
     * content id as hex. Serving needs stations that admit a node's own
     * namespace.
     */
    public function shareContent(
        string $realm,
        string $data,
        string $name = '',
        int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS,
    ): string {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        $buf = Binding::buffer($data);
        $n = Binding::size();
        $mcid = Binding::call(fn ($err) => Binding::ffi()->macula_pool_share_content($h, $realm32, $buf, strlen($data),
            $name, $timeoutMs, \FFI::addr($n), $err), Wire::contentError(...));
        return bin2hex(Binding::takeBytes($mcid, $n->cdata));
    }

    /** Stops sharing mcid in realm and withdraws its announcement. */
    public function unshareContent(string $realm, string $mcid, int $timeoutMs = Wire::DEFAULT_CALL_TIMEOUT_MS): void
    {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        $id = Wire::mcid50($mcid);
        $idBuf = Binding::buffer($id);
        Binding::call(fn ($err) => Binding::ffi()->macula_pool_unshare_content($h, $realm32, $idBuf, strlen($id),
            $timeoutMs, $err), Wire::contentError(...));
    }

    /**
     * Fetches the content mcid names in realm from a node that shares it,
     * checked against mcid; no realm key is needed. Content nobody announces
     * is a NotSharedError, content every sharer failed to give a
     * ContentUnavailableError. Each bound left at 0 is macula's: 256 MiB,
     * 16,384 chunks, 4 streams at once, 15 s a stream.
     */
    public function getContent(
        string $realm,
        string $mcid,
        int $maxBytes = 0,
        int $maxChunks = 0,
        int $parallel = 0,
        int $chunkTimeoutMs = 0,
        int $timeoutMs = Wire::DEFAULT_CONTENT_TIMEOUT_MS,
    ): string {
        $h = $this->live();
        $realm32 = Binding::buffer(Wire::id32($realm, 'realm'));
        $id = Wire::mcid50($mcid);
        $idBuf = Binding::buffer($id);
        $n = Binding::size();
        $data = Binding::call(fn ($err) => Binding::ffi()->macula_pool_get_content($h, $realm32, $idBuf, strlen($id),
            $maxBytes, $maxChunks, $parallel, $chunkTimeoutMs, $timeoutMs, \FFI::addr($n), $err), Wire::contentError(...));
        return Binding::takeBytes($data, $n->cdata);
    }

    /** Closes every link and subscription. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        Binding::ffi()->macula_pool_close($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function live(): int
    {
        if ($this->closed) {
            throw new MaculaException('macula-php: this Pool is closed');
        }
        return $this->handle;
    }
}
