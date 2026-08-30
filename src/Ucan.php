<?php

declare(strict_types=1);

namespace Macula;

/**
 * UCAN (User Controlled Authorization Network) token minting and
 * verification -- a self-contained, delegable capability token,
 * independent of any Session. Mirrors macula-go's `ucan` package,
 * which itself hand-rolls the JWT-shaped UCAN 0.10.0 draft to match
 * `macula_ucan_nif`'s own reference implementation exactly (the current
 * UCAN spec, 1.0.0-rc.1, uses an incompatible non-JWT/IPLD wire format --
 * there is no drop-in library for the older draft this mesh actually
 * speaks, on any language binding, so this is a from-scratch port on both
 * the Go and PHP sides, not a wrapped third-party UCAN implementation).
 */
final class Ucan
{
    /**
     * Mints a new UCAN token, self-issued and signed by $identity.
     * $issuer/$audience are opaque DID strings -- this does not validate
     * or resolve DID structure (that's a separate, unbuilt concern on
     * both the Erlang reference and macula-go).
     *
     * @param list<array{with: string, can: string}> $capabilities
     */
    public static function create(
        string $issuer,
        string $audience,
        array $capabilities,
        KeyPair $identity,
        ?int $expiresAtUnixSec = null,
        ?int $notBeforeUnixSec = null,
    ): string {
        $ffi = Binding::get();
        $capsJson = json_encode($capabilities, JSON_THROW_ON_ERROR);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_ucan_create(
            $issuer,
            $audience,
            $capsJson,
            $expiresAtUnixSec !== null ? 1 : 0, $expiresAtUnixSec ?? 0,
            $notBeforeUnixSec !== null ? 1 : 0, $notBeforeUnixSec ?? 0,
            $identity->handleOrFail(),
            $errOut,
        ));
        $len = $ffi->macula_bytes_handle_len($handle);
        $token = '';
        if ($len > 0) {
            $buf = $ffi->new(\FFI::arrayType($ffi->type('unsigned char'), [$len]));
            $ffi->macula_bytes_handle_read($handle, $buf);
            $token = \FFI::string($buf, $len);
        }
        $ffi->macula_bytes_handle_free($handle);
        return $token;
    }

    /**
     * Verifies $token's signature (against $publicKey32, the issuer's
     * 32-byte Ed25519 public key), expiration, and not-before claims.
     * Throws on any failure; returns the decoded payload only when every
     * check passes.
     */
    public static function verify(string $token, string $publicKey32): UcanPayload
    {
        if (strlen($publicKey32) !== 32) {
            throw new \InvalidArgumentException('a UCAN issuer public key is exactly 32 bytes, got ' . strlen($publicKey32));
        }
        $ffi = Binding::get();
        $tokenBuf = Binding::cBytes($token);
        $pubKeyBuf = Binding::cBytes($publicKey32);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_ucan_verify($tokenBuf, strlen($token), $pubKeyBuf, $errOut));
        return new UcanPayload($handle);
    }

    /** Decodes $token's claims WITHOUT verifying its signature or expiry -- inspect only, never trust. */
    public static function decode(string $token): UcanPayload
    {
        $ffi = Binding::get();
        $tokenBuf = Binding::cBytes($token);
        $handle = Binding::withErrOut(fn ($errOut) => $ffi->macula_ucan_decode($tokenBuf, strlen($token), $errOut));
        return new UcanPayload($handle);
    }

    /** Whether $token's `exp` claim has passed, without checking its signature. */
    public static function isExpired(string $token): bool
    {
        $ffi = Binding::get();
        $tokenBuf = Binding::cBytes($token);
        $result = Binding::withErrOut(fn ($errOut) => $ffi->macula_ucan_is_expired($tokenBuf, strlen($token), $errOut));
        return $result !== 0;
    }
}
