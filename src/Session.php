<?php

declare(strict_types=1);

namespace Macula;

/**
 * A handshaked connection to a macula-station. Wraps an opaque handle
 * into macula-go-sdk's connection.Session.
 *
 * Holds a reference to the KeyPair it was connected with for its whole
 * lifetime -- macula-go-sdk's own Close needs the identity again to
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
     * way macula-go-sdk's own Session.ServeOneCall works.
     */
    public function serveWaitForCall(int $timeoutMs = 15000): PendingCall
    {
        $ffi = Binding::get();
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_serve_wait_for_call($this->handleOrFail(), $this->identity->handleOrFail(), $timeoutMs, $errOut));
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
