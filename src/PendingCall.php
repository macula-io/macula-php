<?php

declare(strict_types=1);

namespace Macula;

/**
 * Provider role: one inbound CALL, waiting for a reply -- what
 * Session::serveWaitForCall() hands back.
 *
 * Session::serveOneCall's Go counterpart bundles "wait for a CALL,
 * invoke a handler, send the reply" into one blocking call with an
 * embedded callback -- PHP has no closure to hand across the FFI
 * boundary, so this object is the split version: replyResult()/
 * replyError() resumes a goroutine blocked on the Go side and blocks
 * in turn until the reply frame is actually sent.
 *
 * Exactly one of replyResult()/replyError() must be called before this
 * object is destroyed -- there's no cross-boundary way to notice "PHP
 * gave up without replying" and unblock the waiting goroutine, so a
 * PendingCall dropped without a reply leaks that goroutine.
 */
final class PendingCall
{
    private ?int $handle;
    private bool $replied = false;

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
     * Cloning would also let both copies race to reply on the same
     * pending call.
     */
    public function __clone(): never
    {
        $this->handle = null;
        throw new \LogicException('PendingCall cannot be cloned -- each instance owns a unique FFI handle');
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
        throw new \LogicException('PendingCall cannot be serialized -- each instance owns a unique FFI handle');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('PendingCall cannot be unserialized -- each instance owns a unique FFI handle');
    }

    public function procedure(): string
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_pending_call_procedure($this->handleOrFail());
        $s = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return $s;
    }

    public function realm(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_pending_call_realm($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    public function payload(): Value
    {
        $ffi = Binding::get();
        $h = $this->handleOrFail();
        return Binding::valueFromParts(
            $ffi->macula_pending_call_payload_kind($h),
            $ffi->macula_pending_call_payload_int($h),
            Binding::readVarBytes(
                fn () => $ffi->macula_pending_call_payload_bytes_len($h),
                fn ($out) => $ffi->macula_pending_call_payload_bytes($h, $out),
            ),
            $ffi->macula_pending_call_payload_float($h),
        );
    }

    /** Reply with a RESULT carrying $payload. */
    public function replyResult(Value $payload): void
    {
        $ffi = Binding::get();
        $bytesBuf = Binding::cBytes($payload->bytesValue);
        Binding::withErrOut(fn ($errOut) => $ffi->macula_pending_call_reply_result(
            $this->handleOrFail(),
            $payload->kind, $payload->intValue, $bytesBuf, strlen($payload->bytesValue), $payload->floatValue,
            $errOut,
        ));
        $this->replied = true;
    }

    /**
     * Reply with a BOLT#4 ERROR (always `unknown_error`, 0x0F -- this
     * split interface has no way to distinguish "unknown procedure"
     * from any other application failure, the same documented
     * limitation macula-rust-ffi's own FfiCallHandler has: that
     * distinction needs a synchronous pre-check ahead of the call,
     * which the split-in-two shape here doesn't have a slot for
     * either. $detail becomes the ERROR frame's own detail field.
     */
    public function replyError(?string $detail = null): void
    {
        $ffi = Binding::get();
        Binding::withErrOut(fn ($errOut) => $ffi->macula_pending_call_reply_error($this->handleOrFail(), $detail, $errOut));
        $this->replied = true;
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this PendingCall has already been freed');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            if (!$this->replied) {
                trigger_error('PendingCall freed without a reply -- the waiting Go goroutine leaks', E_USER_WARNING);
            }
            Binding::get()->macula_pending_call_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
