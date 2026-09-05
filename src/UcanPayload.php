<?php

declare(strict_types=1);

namespace Macula;

/**
 * A UCAN token's decoded claims -- returned by Ucan::verify()/decode().
 * Wraps an opaque handle into macula-go's ucan.Payload, freed
 * automatically when this object is destroyed.
 */
final class UcanPayload
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
        throw new \LogicException('UcanPayload cannot be cloned -- each instance owns a unique FFI handle');
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
        throw new \LogicException('UcanPayload cannot be serialized -- each instance owns a unique FFI handle');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('UcanPayload cannot be unserialized -- each instance owns a unique FFI handle');
    }

    public function issuer(): string
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_ucan_payload_issuer($this->handleOrFail());
        $s = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return $s;
    }

    public function audience(): string
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_ucan_payload_audience($this->handleOrFail());
        $s = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return $s;
    }

    /** Unix seconds, or null if the token carried no `exp` claim. */
    public function expiresAt(): ?int
    {
        $ffi = Binding::get();
        $hasOut = $ffi->new('int');
        $value = $ffi->macula_ucan_payload_expires_at($this->handleOrFail(), \FFI::addr($hasOut));
        return $hasOut->cdata !== 0 ? $value : null;
    }

    /** Unix seconds, or null if the token carried no `nbf` claim. */
    public function notBefore(): ?int
    {
        $ffi = Binding::get();
        $hasOut = $ffi->new('int');
        $value = $ffi->macula_ucan_payload_not_before($this->handleOrFail(), \FFI::addr($hasOut));
        return $hasOut->cdata !== 0 ? $value : null;
    }

    /** @return list<array{with: string, can: string}> */
    public function capabilities(): array
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_ucan_payload_capabilities_json($this->handleOrFail());
        $json = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR) ?? [];
    }

    /** @return list<string> CIDs of parent tokens. */
    public function proofs(): array
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_ucan_payload_proofs_json($this->handleOrFail());
        $json = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR) ?? [];
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this UcanPayload has already been freed');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_ucan_payload_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
