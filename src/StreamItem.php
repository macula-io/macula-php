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

    /**
     * PHP shallow-copies $handle into the clone before this method
     * runs, so nulling it here (not just throwing) is required: without
     * it, the clone's own __destruct() would free the ORIGINAL's handle
     * the moment this throw unwinds and the never-assigned clone is
     * discarded, and the eventual double free() on an already-deleted
     * cgo.Handle panics on the Go side, which is fatal to the whole
     * process if unrecovered. This PHP-side guard remains the primary
     * defense -- it fails fast with a catchable exception instead of
     * relying on cabi/main.go's safeDeleteHandle recover() at all.
     */
    public function __clone(): never
    {
        $this->handle = null;
        throw new \LogicException('StreamItem cannot be cloned -- each instance owns a unique FFI handle');
    }

    /**
     * serialize()/unserialize() is a second door to the same bug
     * clone() guards against -- it copies $handle by value, and
     * unserialize() would hand back a second live object holding that
     * same raw handle, reachable via ordinary PHP ($_SESSION, an
     * object cache, a queue payload), no reflection needed. The handle
     * isn't meaningful across requests/processes anyway.
     */
    public function __serialize(): never
    {
        throw new \LogicException('StreamItem cannot be serialized -- each instance owns a unique FFI handle');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('StreamItem cannot be unserialized -- each instance owns a unique FFI handle');
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
