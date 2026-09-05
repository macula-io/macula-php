<?php

declare(strict_types=1);

namespace Macula;

/**
 * The RESULT or ERROR reply to a CALL. Wraps an opaque handle into
 * macula-go's frame.CallResponse -- freed automatically when this
 * object is destroyed.
 */
final class CallResponse
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
        throw new \LogicException('CallResponse cannot be cloned -- each instance owns a unique FFI handle');
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
        throw new \LogicException('CallResponse cannot be serialized -- each instance owns a unique FFI handle');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('CallResponse cannot be unserialized -- each instance owns a unique FFI handle');
    }

    public function isError(): bool
    {
        return Binding::get()->macula_response_is_error($this->handleOrFail()) !== 0;
    }

    /** Valid when isError() is false. */
    public function payload(): Value
    {
        $ffi = Binding::get();
        $h = $this->handleOrFail();
        return Binding::valueFromParts(
            $ffi->macula_response_result_kind($h),
            $ffi->macula_response_result_int($h),
            Binding::readVarBytes(
                fn () => $ffi->macula_response_result_bytes_len($h),
                fn ($out) => $ffi->macula_response_result_bytes($h, $out),
            ),
            $ffi->macula_response_result_float($h),
        );
    }

    /** Valid when isError() is false. */
    public function respondedBy(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_response_responded_by($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    /** Valid when isError() is true. A BOLT#4 code, 0-255. */
    public function code(): int
    {
        return Binding::get()->macula_response_error_code($this->handleOrFail());
    }

    /** Valid when isError() is true. The BOLT#4 name for code(). */
    public function name(): string
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_response_error_name($this->handleOrFail());
        $s = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return $s;
    }

    /** Valid when isError() is true. */
    public function reportedBy(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_response_reported_by($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    /** Valid when isError() is true. Null if the ERROR carried no detail. */
    public function detail(): ?string
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_response_error_detail($this->handleOrFail());
        // A NULL `char*` *return value* (as opposed to an out-param
        // written through a `char**`) comes back as plain PHP null, not
        // an FFI\CData pointer wrapping NULL -- FFI::isNull() would
        // TypeError on it.
        if ($ptr === null) {
            return null;
        }
        $s = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return $s;
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this CallResponse has already been freed');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_response_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
