<?php

declare(strict_types=1);

namespace Macula;

/**
 * Who may call or open a served procedure (macula's D7 UCANs): only a caller
 * presenting a UCAN chain rooted at the issuer's identity key
 * (ucanRequired), or at a realm key granting a can (realmMemberRequired).
 * libmacula checks each call and open before it reaches PHP: a refused call
 * is a ProviderError of code "unauthorized" (or "malformed_frame" for a proof
 * no token names), a refused open a StreamError of the same code.
 */
final class ServePolicy
{
    /** @param array<string, string> $fields */
    private function __construct(private readonly array $fields)
    {
    }

    /** A chain rooted at the identity key of the node issuerNodeId names
     * (64 hex characters or 32 bytes). */
    public static function ucanRequired(string $issuerNodeId): self
    {
        return new self(['kind' => 'ucan_required', 'issuer' => bin2hex(Wire::id32($issuerNodeId, 'the issuer'))]);
    }

    /** A chain rooted at the realm key whose id keyId names (64 hex
     * characters or 32 bytes), granting can. */
    public static function realmMemberRequired(string $keyId, string $can): self
    {
        if ($can === '') {
            throw new \InvalidArgumentException('macula-php: a realm member policy names a can');
        }
        return new self(['kind' => 'realm_member_required', 'key_id' => bin2hex(Wire::id32($keyId, 'the realm key id')), 'can' => $can]);
    }

    /** @internal The policy as libmacula's policy_json takes it. @return array<string, string> */
    public function toArray(): array
    {
        return $this->fields;
    }
}
