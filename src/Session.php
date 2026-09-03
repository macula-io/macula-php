<?php

declare(strict_types=1);

namespace Macula;

/**
 * A handshaked connection to a macula-station. Wraps an opaque handle
 * into macula-go's connection.Session.
 *
 * Holds a reference to the KeyPair it was connected with for its whole
 * lifetime -- macula-go's own Close needs the identity again to
 * sign the GOODBYE frame, and holding the reference also keeps PHP's
 * refcounting GC from freeing the identity out from under a still-open
 * session (their destruction order isn't otherwise guaranteed).
 */
final class Session
{
    private ?int $handle;

    private function __construct(
        int $handle,
        private readonly KeyPair $identity,
        public readonly bool $accepted,
        public readonly string $stationNodeId,
    ) {
        $this->handle = $handle;
    }

    /**
     * Dial host:port and complete the CONNECT/HELLO handshake, using
     * WebPKI (CA-chain) trust -- the mode the live macula.io fleet
     * actually presents. $timeoutMs bounds the whole operation.
     */
    public static function connect(string $host, int $port, KeyPair $identity, int $timeoutMs = 15000): self
    {
        $ffi = Binding::get();
        $handle = Binding::withErrOut(
            fn (\FFI\CData $errOut) => $ffi->macula_connect(
                $host,
                $port,
                $identity->handleOrFail(),
                $timeoutMs,
                $errOut,
            )
        );

        return self::fromHandle($handle, $identity);
    }

    /**
     * connect()'s multi-station counterpart: tries each of $seeds in
     * order (macula-go's own connection.ConnectSeeds), returning the
     * first that answers -- for a caller that wants to survive one
     * station being down without failing outright, the same fallback
     * macula-cli's own -seed flag gives every direct-dial command.
     * $timeoutMs still bounds the whole operation, across every seed
     * tried, not each one individually.
     *
     * @param string[] $seeds "host[:port]" strings, port defaults to 4433 when omitted; tried in order, at least one required.
     */
    public static function connectSeeds(array $seeds, KeyPair $identity, int $timeoutMs = 15000): self
    {
        if ($seeds === []) {
            throw new \InvalidArgumentException('connectSeeds requires at least one seed');
        }
        $ffi = Binding::get();
        $handle = Binding::withErrOut(
            fn (\FFI\CData $errOut) => $ffi->macula_connect_seeds(
                implode(',', $seeds),
                $identity->handleOrFail(),
                $timeoutMs,
                $errOut,
            )
        );

        return self::fromHandle($handle, $identity);
    }

    /**
     * Wraps a raw session handle already produced by a successful
     * handshake -- used by connect() itself, and by every direct-dial
     * method that dials a NEW connection to a resolved station
     * (streamOpenDirect()) and needs to hand the caller back a usable
     * Session for it, the same way connect() does.
     */
    private static function fromHandle(int $handle, KeyPair $identity): self
    {
        $ffi = Binding::get();
        $accepted = $ffi->macula_session_accepted($handle) !== 0;
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_session_station_node_id($handle, $buf);
        $stationNodeId = Binding::readBytes32($buf);

        return new self($handle, $identity, $accepted, $stationNodeId);
    }

    /**
     * Send a signed CALL for $procedure and wait for the matching
     * RESULT or ERROR, correlated by call_id. $realm must be exactly
     * 32 bytes.
     */
    public function call(string $procedure, string $realm, Value $payload, int $timeoutMs = 15000): CallResponse
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $payloadBuf = Binding::cBytes($payload->bytesValue);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_call(
            $this->handleOrFail(),
            $procedure,
            $realmBuf,
            $payload->kind, $payload->intValue, $payloadBuf, strlen($payload->bytesValue), $payload->floatValue,
            $timeoutMs,
            $this->identity->handleOrFail(),
            $errOut,
        ));
        return new CallResponse($handle);
    }

    /**
     * Send a signed PUBLISH. Fire-and-forget -- a subscriber (this
     * session included, if subscribed to the same topic/realm) receives
     * it asynchronously via recvEvent().
     *
     * $seq and $publishedAtMs are caller-supplied rather than tracked
     * internally: PUBLISH's seq is a per-publisher, per-topic sequence
     * the mesh uses for gap detection, and a client publishing to
     * several topics has to own that bookkeeping itself.
     */
    public function publish(string $topic, string $realm, int $seq, Value $payload, int $publishedAtMs): void
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $payloadBuf = Binding::cBytes($payload->bytesValue);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_publish(
            $this->handleOrFail(),
            $topic,
            $realmBuf,
            $seq,
            $payload->kind, $payload->intValue, $payloadBuf, strlen($payload->bytesValue), $payload->floatValue,
            $publishedAtMs,
            $this->identity->handleOrFail(),
            $errOut,
        ));
    }

    /** Send a signed SUBSCRIBE. Fire-and-forget -- deliveries arrive via recvEvent(). */
    public function subscribe(string $topic, string $realm): void
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_subscribe($this->handleOrFail(), $topic, $realmBuf, $this->identity->handleOrFail(), $errOut));
    }

    /** Send a signed UNSUBSCRIBE. Fire-and-forget. */
    public function unsubscribe(string $topic, string $realm): void
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_unsubscribe($this->handleOrFail(), $topic, $realmBuf, $this->identity->handleOrFail(), $errOut));
    }

    /**
     * Send a signed ADVERTISE -- registers this session as the handler
     * for $procedure under $realm. Fire-and-forget; the station then
     * routes inbound CALLs and STREAM_OPENs for it back to us.
     */
    public function advertise(string $procedure, string $realm): void
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_advertise($this->handleOrFail(), $procedure, $realmBuf, $this->identity->handleOrFail(), $errOut));
    }

    /** Send a signed UNADVERTISE. Fire-and-forget. */
    public function unadvertise(string $procedure, string $realm): void
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_unadvertise($this->handleOrFail(), $procedure, $realmBuf, $this->identity->handleOrFail(), $errOut));
    }

    /**
     * Block for the next EVENT delivery, bounded by $timeoutMs. Any
     * non-EVENT frame received first is an error, not silently skipped.
     */
    public function recvEvent(int $timeoutMs = 15000): Event
    {
        $ffi = Binding::get();
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_recv_event($this->handleOrFail(), $timeoutMs, $errOut));
        return new Event($handle);
    }

    /**
     * Store $data under a content-address, returning its MCID (34
     * raw bytes). $name is attached to the manifest when $data is large
     * enough to be chunked; silently unused for a single block, which
     * is addressed purely by content hash.
     */
    public function contentPut(string $data, string $name): string
    {
        $ffi = Binding::get();
        $dataBuf = Binding::cBytes($data);
        $mcidBuf = $ffi->new('unsigned char[34]');
        Binding::withErrOut(function ($errOut) use ($ffi, $dataBuf, $data, $name, $mcidBuf) {
            $ffi->macula_content_put($this->handleOrFail(), $dataBuf, strlen($data), $name, $this->identity->handleOrFail(), $mcidBuf, $errOut);
        });
        return \FFI::string($mcidBuf, 34);
    }

    /** Fetch and verify the content addressed by $mcid (34 raw bytes). */
    public function contentGet(string $mcid): string
    {
        if (strlen($mcid) !== 34) {
            throw new \InvalidArgumentException('an MCID is exactly 34 bytes, got ' . strlen($mcid));
        }
        $ffi = Binding::get();
        $mcidBuf = Binding::cBytes($mcid);
        $bytesHandle = Binding::withErrOut(fn ($errOut) => $ffi->macula_content_get($this->handleOrFail(), $mcidBuf, $this->identity->handleOrFail(), $errOut));
        $len = $ffi->macula_bytes_handle_len($bytesHandle);
        $data = '';
        if ($len > 0) {
            $buf = $ffi->new(\FFI::arrayType($ffi->type('unsigned char'), [$len]));
            $ffi->macula_bytes_handle_read($bytesHandle, $buf);
            $data = \FFI::string($buf, $len);
        }
        $ffi->macula_bytes_handle_free($bytesHandle);
        return $data;
    }

    /**
     * Open a dedicated stream and send a signed STREAM_OPEN. $realm
     * must be exactly 32 bytes. $deadlineMs is the frame's own deadline
     * field -- there's no open-time acknowledgement to wait for on the
     * wire; the provider starts reacting to it directly.
     */
    public function streamOpen(string $procedure, string $realm, int $mode, Value $args, int $deadlineMs): StreamHandle
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $argsBuf = Binding::cBytes($args->bytesValue);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_open(
            $this->handleOrFail(),
            $procedure,
            $realmBuf,
            $mode,
            $args->kind, $args->intValue, $argsBuf, strlen($args->bytesValue), $args->floatValue,
            $deadlineMs,
            $this->identity->handleOrFail(),
            $errOut,
        ));
        return new StreamHandle($handle, $this->identity);
    }

    /**
     * Provider role: block for the next inbound STREAM_OPEN, bounded by
     * $timeoutMs. Only ever succeeds after advertise() has registered
     * at least one procedure. Returns the ready-to-use handle alongside
     * the STREAM_OPEN info (check its procedure() if this session
     * advertised more than one).
     *
     * @return array{0: StreamHandle, 1: StreamOpenInfo}
     */
    public function streamAccept(int $timeoutMs = 15000): array
    {
        $ffi = Binding::get();
        $infoHandleOut = $ffi->new('uintptr_t');
        $streamHandle = Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_accept(
            $this->handleOrFail(),
            $timeoutMs,
            \FFI::addr($infoHandleOut),
            $errOut,
        ));
        return [new StreamHandle($streamHandle, $this->identity), new StreamOpenInfo($infoHandleOut->cdata)];
    }

    /**
     * The provider role's counterpart to call(): block for the next
     * inbound CALL, bounded by $timeoutMs, and return it as a
     * PendingCall for the caller to inspect and reply to. Only ever
     * succeeds after advertise() has registered at least one procedure.
     *
     * See PendingCall's own doc for why this is split into two steps
     * (wait, then reply) rather than one call taking a handler, the
     * way macula-go's own Session.ServeOneCall works.
     */
    public function serveWaitForCall(int $timeoutMs = 15000): PendingCall
    {
        $ffi = Binding::get();
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_serve_wait_for_call($this->handleOrFail(), $this->identity->handleOrFail(), $timeoutMs, $errOut));
        return new PendingCall($handle);
    }

    /**
     * Resolves $procedure's currently-advertised serving station via the
     * mesh DHT -- this session is used only to query it, and need not be
     * connected to the station that will end up serving a call for it.
     *
     * @return array{station: string, host: string, port: int}
     */
    public function resolveDirect(string $procedure, string $realm): array
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $stationBuf = $ffi->new('unsigned char[32]');
        $portBuf = $ffi->new('uint16_t');
        $hostPtr = Binding::withErrOut(fn ($errOut) => $ffi->macula_resolve_direct(
            $this->handleOrFail(), $procedure, $realmBuf, $this->identity->handleOrFail(),
            $stationBuf, \FFI::addr($portBuf), $errOut,
        ));
        $host = \FFI::string($hostPtr);
        $ffi->macula_free_string($hostPtr);
        return ['station' => Binding::readBytes32($stationBuf), 'host' => $host, 'port' => $portBuf->cdata];
    }

    /**
     * resolveDirect(), plus Slice 7c Direction B managed-realm
     * authorization: only an advertisement whose embedded cert chain
     * validates to $realmCaPem and names $expectedOrg is trusted.
     *
     * @return array{station: string, host: string, port: int}
     */
    public function resolveDirectWithCertChain(string $procedure, string $realm, string $realmCaPem, string $expectedOrg): array
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $caPemBuf = Binding::cBytes($realmCaPem);
        $stationBuf = $ffi->new('unsigned char[32]');
        $portBuf = $ffi->new('uint16_t');
        $hostPtr = Binding::withErrOut(fn ($errOut) => $ffi->macula_resolve_direct_with_cert_chain(
            $this->handleOrFail(), $procedure, $realmBuf, $caPemBuf, strlen($realmCaPem), $expectedOrg,
            $this->identity->handleOrFail(), $stationBuf, \FFI::addr($portBuf), $errOut,
        ));
        $host = \FFI::string($hostPtr);
        $ffi->macula_free_string($hostPtr);
        return ['station' => Binding::readBytes32($stationBuf), 'host' => $host, 'port' => $portBuf->cdata];
    }

    /**
     * Resolves $procedure via the mesh DHT (using this session to query
     * it) and dials its serving station directly, in one hop -- instead
     * of depending on ordinary advertise-gossip having propagated a route
     * between whichever two stations happen to be involved.
     */
    public function callDirect(string $procedure, string $realm, Value $payload, int $timeoutMs = 15000): CallResponse
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $payloadBuf = Binding::cBytes($payload->bytesValue);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_call_direct(
            $this->handleOrFail(), $procedure, $realmBuf,
            $payload->kind, $payload->intValue, $payloadBuf, strlen($payload->bytesValue), $payload->floatValue,
            $timeoutMs, $this->identity->handleOrFail(), $errOut,
        ));
        return new CallResponse($handle);
    }

    /** callDirect(), resolved via managed-realm cert-chain authorization instead of plain trust. */
    public function callDirectWithCertChain(string $procedure, string $realm, string $realmCaPem, string $expectedOrg, Value $payload, int $timeoutMs = 15000): CallResponse
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $caPemBuf = Binding::cBytes($realmCaPem);
        $payloadBuf = Binding::cBytes($payload->bytesValue);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_call_direct_with_cert_chain(
            $this->handleOrFail(), $procedure, $realmBuf, $caPemBuf, strlen($realmCaPem), $expectedOrg,
            $payload->kind, $payload->intValue, $payloadBuf, strlen($payload->bytesValue), $payload->floatValue,
            $timeoutMs, $this->identity->handleOrFail(), $errOut,
        ));
        return new CallResponse($handle);
    }

    /**
     * callDirect(), presenting $ucanToken to a provider gated with
     * `{ucan_required, Issuer}`. Every hecate-om capability is advertised
     * via advertiseDirect(), so this is the only way this SDK can reach a
     * UCAN-gated capability at all -- callDirect() has no token
     * parameter, and callWithUcan() is the plain, non-direct path, which
     * cannot resolve a direct-dial-only advertisement to begin with.
     */
    public function callDirectWithUcan(string $procedure, string $realm, Value $payload, string $ucanToken, int $timeoutMs = 15000): CallResponse
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $payloadBuf = Binding::cBytes($payload->bytesValue);
        $tokenBuf = Binding::cBytes($ucanToken);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_call_direct_with_ucan(
            $this->handleOrFail(), $procedure, $realmBuf,
            $payload->kind, $payload->intValue, $payloadBuf, strlen($payload->bytesValue), $payload->floatValue,
            $timeoutMs, $this->identity->handleOrFail(), $tokenBuf, strlen($ucanToken), $errOut,
        ));
        return new CallResponse($handle);
    }

    /**
     * Publishes a signed procedure_advertisement DHT record naming this
     * session's own currently-connected station as $procedure's server,
     * discoverable by any caller's resolveDirect()/callDirect(). One-shot:
     * a station's registration for a procedure does not survive the
     * connection that sent it being replaced, so a long-lived server
     * calls this again on its own schedule -- e.g. a simple loop calling
     * advertiseDirect() every few minutes, well inside $ttlMs. This SDK
     * intentionally does not wrap that loop in a background Go routine:
     * a PHP process can trivially host its own loop, and doing so avoids
     * inventing callback plumbing across the FFI boundary for something
     * a plain `while` loop already solves.
     */
    public function advertiseDirect(string $procedure, string $realm, int $ttlMs): void
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_advertise_direct(
            $this->handleOrFail(), $procedure, $realmBuf, $ttlMs, $this->identity->handleOrFail(), $errOut,
        ));
    }

    /** advertiseDirect(), additionally embedding $certChainPem so a resolveDirectWithCertChain() caller can authorize this advertiser for a specific org. */
    public function advertiseDirectWithCertChain(string $procedure, string $realm, int $ttlMs, string $certChainPem): void
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $certBuf = Binding::cBytes($certChainPem);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_advertise_direct_with_cert_chain(
            $this->handleOrFail(), $procedure, $realmBuf, $ttlMs, $certBuf, strlen($certChainPem), $this->identity->handleOrFail(), $errOut,
        ));
    }

    /**
     * streamOpen()'s direct-dial counterpart: resolves $procedure via the
     * mesh DHT and dials its serving station directly, then opens a
     * dedicated stream against that NEW connection. The returned Session
     * is distinct from this one (direct-dial always opens a fresh
     * connection to the resolved station) -- the caller owns it and
     * should eventually close() it, same as any other Session.
     *
     * @return array{0: Session, 1: StreamHandle}
     */
    public function streamOpenDirect(string $procedure, string $realm, int $mode, Value $args, int $deadlineMs, int $timeoutMs = 15000): array
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $argsBuf = Binding::cBytes($args->bytesValue);
        $sessionHandleOut = $ffi->new('uintptr_t');
        $streamHandle = Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_open_direct(
            $this->handleOrFail(), $procedure, $realmBuf, $mode,
            $args->kind, $args->intValue, $argsBuf, strlen($args->bytesValue), $args->floatValue,
            $deadlineMs, $timeoutMs, $this->identity->handleOrFail(), \FFI::addr($sessionHandleOut), $errOut,
        ));
        $newSession = self::fromHandle($sessionHandleOut->cdata, $this->identity);
        return [$newSession, new StreamHandle($streamHandle, $this->identity)];
    }

    /** streamOpenDirect(), resolved via managed-realm cert-chain authorization. @return array{0: Session, 1: StreamHandle} */
    public function streamOpenDirectWithCertChain(string $procedure, string $realm, string $realmCaPem, string $expectedOrg, int $mode, Value $args, int $deadlineMs, int $timeoutMs = 15000): array
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $caPemBuf = Binding::cBytes($realmCaPem);
        $argsBuf = Binding::cBytes($args->bytesValue);
        $sessionHandleOut = $ffi->new('uintptr_t');
        $streamHandle = Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_open_direct_with_cert_chain(
            $this->handleOrFail(), $procedure, $realmBuf, $caPemBuf, strlen($realmCaPem), $expectedOrg, $mode,
            $args->kind, $args->intValue, $argsBuf, strlen($args->bytesValue), $args->floatValue,
            $deadlineMs, $timeoutMs, $this->identity->handleOrFail(), \FFI::addr($sessionHandleOut), $errOut,
        ));
        $newSession = self::fromHandle($sessionHandleOut->cdata, $this->identity);
        return [$newSession, new StreamHandle($streamHandle, $this->identity)];
    }

    /**
     * Stores $data directly on a KNOWN station (its 32-byte node id --
     * e.g. one already returned by resolveDirect(), or known out of
     * band), dialing it directly. Unlike a procedure advertisement,
     * content storage has no "advertisement" step of its own to resolve
     * first, matching macula-go's own PutDirect design.
     */
    public function putDirect(string $station, string $data, string $name, int $timeoutMs = 15000): string
    {
        if (strlen($station) !== 32) {
            throw new \InvalidArgumentException('a station id is exactly 32 bytes, got ' . strlen($station));
        }
        $ffi = Binding::get();
        $stationBuf = Binding::cBytes($station);
        $dataBuf = Binding::cBytes($data);
        $mcidBuf = $ffi->new('unsigned char[34]');
        Binding::withErrOut(function ($errOut) use ($ffi, $stationBuf, $dataBuf, $data, $name, $timeoutMs, $mcidBuf) {
            $ffi->macula_put_direct($this->handleOrFail(), $stationBuf, $dataBuf, strlen($data), $name, $timeoutMs, $this->identity->handleOrFail(), $mcidBuf, $errOut);
        });
        return \FFI::string($mcidBuf, 34);
    }

    /**
     * Fetches content addressed by $mcid, resolving its serving endpoint
     * via a content_announcement DHT record -- published only by
     * something independently-dialable (e.g. a station/relay). A leaf
     * SDK identity cannot legitimately publish one, so there is no
     * announceContentDirect() in this SDK, matching macula-go's own
     * scope exactly: this method can only succeed against content a
     * station/relay itself announced, not arbitrary putDirect() output.
     */
    public function getDirect(string $mcid, int $timeoutMs = 15000): string
    {
        if (strlen($mcid) !== 34) {
            throw new \InvalidArgumentException('an MCID is exactly 34 bytes, got ' . strlen($mcid));
        }
        $ffi = Binding::get();
        $mcidBuf = Binding::cBytes($mcid);
        $bytesHandle = Binding::withErrOut(fn ($errOut) => $ffi->macula_get_direct($this->handleOrFail(), $mcidBuf, $timeoutMs, $this->identity->handleOrFail(), $errOut));
        $len = $ffi->macula_bytes_handle_len($bytesHandle);
        $data = '';
        if ($len > 0) {
            $buf = $ffi->new(\FFI::arrayType($ffi->type('unsigned char'), [$len]));
            $ffi->macula_bytes_handle_read($bytesHandle, $buf);
            $data = \FFI::string($buf, $len);
        }
        $ffi->macula_bytes_handle_free($bytesHandle);
        return $data;
    }

    /** call(), with a UCAN token attached for a policy-gated procedure. */
    public function callWithUcan(string $procedure, string $realm, Value $payload, string $ucanToken, int $timeoutMs = 15000): CallResponse
    {
        $ffi = Binding::get();
        $realmBuf = Binding::cBytes($realm);
        $payloadBuf = Binding::cBytes($payload->bytesValue);
        $tokenBuf = Binding::cBytes($ucanToken);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_call_with_ucan(
            $this->handleOrFail(), $procedure, $realmBuf,
            $payload->kind, $payload->intValue, $payloadBuf, strlen($payload->bytesValue), $payload->floatValue,
            $timeoutMs, $this->identity->handleOrFail(), $tokenBuf, strlen($ucanToken), $errOut,
        ));
        return new CallResponse($handle);
    }

    /**
     * serveWaitForCall()'s UCAN-gated counterpart: a caller must present
     * a token verifying against $requiredIssuer32 (its 32-byte Ed25519
     * public key) before this ever returns a PendingCall for a handler to
     * see -- a rejected caller is refused by the station-facing dispatch
     * itself and never reaches PHP at all. Pass null for an open
     * (ungated) policy, equivalent to plain serveWaitForCall().
     */
    public function serveWaitForCallGated(?string $requiredIssuer32, int $timeoutMs = 15000): PendingCall
    {
        $ffi = Binding::get();
        $issuerBuf = null;
        if ($requiredIssuer32 !== null) {
            if (strlen($requiredIssuer32) !== 32) {
                throw new \InvalidArgumentException('a UCAN issuer public key is exactly 32 bytes, got ' . strlen($requiredIssuer32));
            }
            $issuerBuf = Binding::cBytes($requiredIssuer32);
        }
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_serve_wait_for_call_gated(
            $this->handleOrFail(), $this->identity->handleOrFail(), $timeoutMs, $issuerBuf, $errOut,
        ));
        return new PendingCall($handle);
    }

    /** Sends a GOODBYE and closes the connection. A no-op if already closed. */
    public function close(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_session_close($this->handle, $this->identity->handleOrFail());
            $this->handle = null;
        }
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this Session has already been closed');
        }
        return $this->handle;
    }

    public function __destruct()
    {
        $this->close();
    }
}
