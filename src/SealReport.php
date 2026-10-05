<?php

declare(strict_types=1);

namespace Macula;

/**
 * Whether the exchange behind a result was sealed end to end, and to which
 * key (macula's seal report): `sealed`, the `provider` node it was addressed
 * to (hex), and the 8-byte id of the key it was sealed to (16 hex), null for
 * a clear exchange. It states that sealing ran on that exchange, nothing more.
 */
final class SealReport
{
    public function __construct(
        public readonly bool $sealed,
        public readonly string $provider,
        public readonly ?string $sealKeyId,
    ) {
    }

    /** @internal */
    public static function fromArray(array $r): self
    {
        return new self(($r['sealed'] ?? 0) === 1, (string) $r['provider'], $r['seal_key_id'] ?? null);
    }
}
