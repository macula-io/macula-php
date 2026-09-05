<?php

declare(strict_types=1);

namespace Macula;

/**
 * Provider role: the fields of an inbound STREAM_OPEN -- which
 * procedure, whose call, what arguments. Mirrors frame.StreamOpenInfo.
 * Returned alongside a StreamHandle by Session::streamAccept().
 */
final class StreamOpenInfo
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
        throw new \LogicException('StreamOpenInfo cannot be cloned -- each instance owns a unique FFI handle');
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
        throw new \LogicException('StreamOpenInfo cannot be serialized -- each instance owns a unique FFI handle');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('StreamOpenInfo cannot be unserialized -- each instance owns a unique FFI handle');
    }

    public function procedure(): string
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_stream_open_info_procedure($this->handleOrFail());
        $s = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return $s;
    }

    public function realm(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_stream_open_info_realm($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    /** One of StreamMode::SERVER_STREAM / CLIENT_STREAM / BIDI. */
    public function mode(): int
    {
        return Binding::get()->macula_stream_open_info_mode($this->handleOrFail());
    }

    public function args(): Value
    {
        $ffi = Binding::get();
        $h = $this->handleOrFail();
        return Binding::valueFromParts(
            $ffi->macula_stream_open_info_args_kind($h),
            $ffi->macula_stream_open_info_args_int($h),
            Binding::readVarBytes(
                fn () => $ffi->macula_stream_open_info_args_bytes_len($h),
                fn ($out) => $ffi->macula_stream_open_info_args_bytes($h, $out),
            ),
            $ffi->macula_stream_open_info_args_float($h),
        );
    }

    public function deadlineMs(): int
    {
        return Binding::get()->macula_stream_open_info_deadline_ms($this->handleOrFail());
    }

    public function caller(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_stream_open_info_caller($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this StreamOpenInfo has already been freed');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_stream_open_info_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
