<?php

declare(strict_types=1);

namespace Macula;

/** UCAN helpers. A token is minted with NodeKey::ucan. */
final class Ucan
{
    /** The proof id a child token's `prf` names token by: lowercase hex
     * SHA-384 of its text. */
    public static function proofId(string $token): string
    {
        return Binding::takeString(Binding::call(fn ($err) => Binding::ffi()->macula_ucan_proof_id($token, $err)));
    }
}
