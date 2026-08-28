<?php

declare(strict_types=1);

namespace Macula;

/**
 * One item StreamHandle::recv() hands back: a chunk, or a clean
 * end-of-stream. Mirrors stream.Item.
 */
final class StreamItem
{
    private ?int $handle;

    /** @internal */
    public function __construct(int $handle)
    {
        $this->handle = $handle;
    }

    public function isEof(): bool
    {
        return Binding::get()->macula_stream_item_is_eof($this->handleOrFail()) !== 0;
    }

    /** Valid when isEof() is false. */
    public function seq(): int
    {
        return Binding::get()->macula_stream_item_seq($this->handleOrFail());
    }

    /** Valid when isEof() is false. One of StreamEncoding::RAW / MSGPACK. */
    public function encoding(): int
    {
        return Binding::get()->macula_stream_item_encoding($this->handleOrFail());
    }

    /** Valid when isEof() is false. */
    public function body(): Value
    {
        $ffi = Binding::get();
        $h = $this->handleOrFail();
        return Binding::valueFromParts(
            $ffi->macula_stream_item_body_kind($h),
            $ffi->macula_stream_item_body_int($h),
            Binding::readVarBytes(
                fn () => $ffi->macula_stream_item_body_bytes_len($h),
                fn ($out) => $ffi->macula_stream_item_body_bytes($h, $out),
            ),
            $ffi->macula_stream_item_body_float($h),
        );
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this StreamItem has already been freed');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_stream_item_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
