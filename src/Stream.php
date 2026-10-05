<?php

declare(strict_types=1);

namespace Macula;

/**
 * A streaming session, on either side: the caller's from Pool::openStream, or
 * a provider's from ServedStream. Each session is a QUIC stream of its own;
 * frames are signed by each side and verified before they are handed on.
 * Iterating a stream gives every frame until it ends.
 *
 * @implements \IteratorAggregate<int, StreamEvent>
 */
final class Stream implements \IteratorAggregate
{
    private bool $freed = false;

    /** @internal */
    public function __construct(private readonly int $handle, private readonly BytesOutput $bytes)
    {
    }

    /** The stream's open: who opened it, where, and its payload. */
    public function request(): Request
    {
        $h = $this->live();
        return Request::fromJson(Binding::takeString(Binding::call(fn ($err) =>
            Binding::ffi()->macula_stream_request($h, $err))), $this->bytes);
    }

    /** Sends a raw chunk. */
    /** A caller stream's seal report, once it settled on the provider's first
     * data or reply (on a clear stream, its first data, reply or end). Before
     * that, a MaculaException of kind "not_settled"; on a served stream,
     * "not_a_caller". */
    public function report(): SealReport
    {
        $h = $this->live();
        return SealReport::fromArray(Wire::decode(Binding::takeString(Binding::call(fn ($err) =>
            Binding::ffi()->macula_stream_report($h, $err)))));
    }

    public function send(string $chunk): void
    {
        $h = $this->live();
        $buf = Binding::buffer($chunk);
        Binding::call(fn ($err) => Binding::ffi()->macula_stream_send_bytes($h, $buf, strlen($chunk), $err));
    }

    /** Sends a structured chunk. */
    public function sendValue(mixed $value): void
    {
        $h = $this->live();
        $json = Wire::encode($value);
        Binding::call(fn ($err) => Binding::ffi()->macula_stream_send_json($h, $json, $err));
    }

    /** Ends this side's sending; the peer may still send. */
    public function closeSend(): void
    {
        $h = $this->live();
        Binding::call(fn ($err) => Binding::ffi()->macula_stream_close_send($h, $err));
    }

    /** Ends the stream on both sides. */
    public function close(): void
    {
        $h = $this->live();
        Binding::call(fn ($err) => Binding::ffi()->macula_stream_close($h, $err));
    }

    /** The provider's terminal value; ends the stream. */
    public function reply(mixed $payload): void
    {
        $h = $this->live();
        $json = Wire::encode($payload);
        Binding::call(fn ($err) => Binding::ffi()->macula_stream_reply($h, $json, $err));
    }

    /** Ends the stream with a STREAM_ERROR the peer sees. */
    public function abort(string $code, string $message = ''): void
    {
        $h = $this->live();
        Binding::call(fn ($err) => Binding::ffi()->macula_stream_abort($h, $code, $message, $err));
    }

    /**
     * The peer's next frame, or null once the stream has ended normally. A
     * stream error is thrown as a StreamError; timeoutMs (0 for none) bounds
     * the wait with a MaculaException "timeout".
     */
    public function recv(int $timeoutMs = 0): ?StreamEvent
    {
        $h = $this->live();
        $e = Wire::decodeOutput(Binding::takeString(Binding::call(fn ($err) =>
            Binding::ffi()->macula_stream_recv($h, $timeoutMs, 0, $err))), $this->bytes);
        return match ($e['kind']) {
            'eof' => null,
            'error' => throw new StreamError($e['code'], $e['message'] ?? '', ($e['relay'] ?? 0) === 1),
            'data' => new StreamData($e['encoding'], $e['body']),
            'end' => new StreamEnd($e['role']),
            default => new StreamReply($e['payload']),
        };
    }

    /** Every frame until the stream ends. @return \Generator<int, StreamEvent> */
    public function getIterator(): \Generator
    {
        while (($event = $this->recv()) !== null) {
            yield $event;
        }
    }

    /** Releases the stream, aborting it first when it has not ended. */
    public function free(): void
    {
        if ($this->freed) {
            return;
        }
        $this->freed = true;
        Binding::ffi()->macula_stream_free($this->handle);
    }

    public function __destruct()
    {
        $this->free();
    }

    private function live(): int
    {
        if ($this->freed) {
            throw new MaculaException('macula-php: this Stream was freed');
        }
        return $this->handle;
    }
}
