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

    /**
     * PHP shallow-copies $handle into the clone before running this
     * method, so the clone already holds a live copy of the same raw
     * handle at this point -- if left alone, its own __destruct() would
     * free the ORIGINAL's handle out from under it the moment this
     * throw unwinds and the clone (never assigned to a variable) is
     * discarded. Null it first so that free() is a no-op on the clone,
     * THEN throw -- the exception must not depend on which line runs
     * first, since either order without both steps still crashes on a
     * build without the cabi-side recover() (safeDeleteHandle in
     * cabi/main.go): a double free() on an already-deleted cgo.Handle
     * panics on the Go side, which is fatal to the whole process if
     * unrecovered. This PHP-side guard remains the primary defense --
     * it fails fast with a catchable exception instead of relying on
     * that recover() at all.
     */
    public function __clone(): never
    {
        $this->handle = null;
        throw new \LogicException('KeyPair cannot be cloned -- each instance owns a unique FFI handle');
    }

    /**
     * serialize()/unserialize() is a second door to the exact same bug
     * clone() has: it copies $handle by value into the serialized
     * representation, and unserialize() would hand back a second live
     * object holding that same raw handle -- reachable via ordinary PHP
     * ($_SESSION, an object cache, a queue payload), no reflection
     * needed. Block both directions; the handle isn't meaningful across
     * requests/processes anyway. Persist the identity by value instead:
     * fromSeedBytes(privateBytes()) reconstructs an equivalent KeyPair.
     */
    public function __serialize(): never
    {
        throw new \LogicException('KeyPair cannot be serialized -- persist privateBytes() and rebuild with fromSeedBytes()');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('KeyPair cannot be unserialized -- persist privateBytes() and rebuild with fromSeedBytes()');
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
