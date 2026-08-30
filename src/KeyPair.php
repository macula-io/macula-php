<?php

declare(strict_types=1);

namespace Macula;

/**
 * A puzzle-hardened Ed25519 identity. Wraps an opaque handle into
 * macula-go's identity.KeyPair, generated on the Go side (via
 * identity.Generate(), already S/Kademlia puzzle-hardened by default --
 * see that function's own doc for why this is always the right choice).
 */
final class KeyPair
{
    private ?int $handle;

    private function __construct(int $handle)
    {
        $this->handle = $handle;
    }

    public static function generate(): self
    {
        $ffi = Binding::get();
        $handle = Binding::withErrOut(
            fn (\FFI\CData $errOut) => $ffi->macula_identity_generate($errOut)
        );
        return new self($handle);
    }

    /**
     * Reconstruct a keypair from its 32-byte seed (see privateBytes()) --
     * deterministic, the same seed always yields the same node_id. The
     * seed came from a puzzle-hardened generate() call, so reconstructing
     * from it stays puzzle-valid too; puzzle validity is a property of
     * the public key this seed determines, not something re-checked at
     * reconstruction time.
     */
    public static function fromSeedBytes(string $seed): self
    {
        if (strlen($seed) !== 32) {
            throw new \InvalidArgumentException(
                "seed must be exactly 32 bytes, got " . strlen($seed)
            );
        }
        $ffi = Binding::get();
        $seedBuf = Binding::cBytes($seed);
        $handle = Binding::withErrOut(
            fn (\FFI\CData $errOut) => $ffi->macula_identity_from_seed_bytes($seedBuf, $errOut)
        );
        return new self($handle);
    }

    /** This identity's node_id (its Ed25519 public key), 32 bytes. */
    public function nodeId(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_identity_node_id($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    /**
     * This identity's 32-byte seed. Persist it to restore the SAME
     * identity (same node_id) across restarts via fromSeedBytes() --
     * treat it like a private key, since it deterministically
     * reconstructs this keypair.
     */
    public function privateBytes(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_identity_private_bytes($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    /** @internal */
    public function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this KeyPair has already been freed');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_identity_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
