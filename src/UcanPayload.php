<?php

declare(strict_types=1);

namespace Macula;

/**
 * A UCAN token's decoded claims -- returned by Ucan::verify()/decode().
 * Wraps an opaque handle into macula-go-sdk's ucan.Payload, freed
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
