<?php

declare(strict_types=1);

namespace Macula;

/**
 * A puzzle-hardened Ed25519 identity. Wraps an opaque handle into
 * macula-go-sdk's identity.KeyPair, generated on the Go side (via
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

    /** This identity's node_id (its Ed25519 public key), 32 bytes. */
    public function nodeId(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_identity_node_id($this->handleOrFail(), $buf);
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
