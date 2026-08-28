<?php

declare(strict_types=1);

namespace Macula;

/**
 * A streaming RPC exchange -- caller (via Session::streamOpen()) or
 * provider (via Session::streamAccept()) role, the wire vocabulary is
 * symmetric either way. Wraps an opaque handle into macula-go-sdk's
 * stream.Handle.
 *
 * Holds the KeyPair it was opened/accepted with, same reasoning as
 * Session holding its own identity: every send/close/reply/abort needs
 * to sign with it, and holding the reference keeps PHP's GC from
 * freeing it out from under a still-open stream.
 */
final class StreamHandle
{
    private ?int $handle;

    /** @internal */
    public function __construct(int $handle, private readonly KeyPair $identity)
    {
        $this->handle = $handle;
    }

    /** Send one chunk. */
    public function sendData(int $encoding, Value $body): void
    {
        $ffi = Binding::get();
        $bytesBuf = Binding::cBytes($body->bytesValue);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_send_data(
            $this->handleOrFail(),
            $encoding,
            $body->kind, $body->intValue, $bytesBuf, strlen($body->bytesValue), $body->floatValue,
            $this->identity->handleOrFail(),
            $errOut,
        ));
    }

    /** Half-close: signal this side is done sending. */
    public function closeSend(): void
    {
        $ffi = Binding::get();
        Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_close_send($this->handleOrFail(), $this->identity->handleOrFail(), $errOut));
    }

    /** Receive the next chunk or end-of-stream, bounded by $timeoutMs. */
    public function recv(int $timeoutMs = 15000): StreamItem
    {
        $ffi = Binding::get();
        $itemHandle = Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_recv($this->handleOrFail(), $timeoutMs, $errOut));
        return new StreamItem($itemHandle);
    }

    /** Block for the provider's terminal STREAM_REPLY (client_stream/bidi modes only). */
    public function awaitReply(int $timeoutMs = 15000): StreamReply
    {
        $ffi = Binding::get();
        $replyHandle = Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_await_reply($this->handleOrFail(), $timeoutMs, $errOut));
        return new StreamReply($replyHandle);
    }

    /** Provider role: send the terminal STREAM_REPLY. */
    public function sendReply(Value $payload): void
    {
        $ffi = Binding::get();
        $bytesBuf = Binding::cBytes($payload->bytesValue);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_stream_send_reply(
            $this->handleOrFail(),
            $payload->kind, $payload->intValue, $bytesBuf, strlen($payload->bytesValue), $payload->floatValue,
            $this->identity->handleOrFail(),
            $errOut,
        ));
    }

    /**
     * Non-normal termination: explicitly tell the peer this stream is
     * aborting, rather than just dropping it -- the peer's only signal
     * to distinguish a cancellation/failure from a dropped connection.
     * Best-effort; frees the handle.
     */
    public function abort(string $code, string $message): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_stream_abort($this->handle, $code, $message, $this->identity->handleOrFail());
            $this->handle = null;
        }
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this StreamHandle has already been freed/aborted');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_stream_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
