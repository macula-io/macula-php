<?php

declare(strict_types=1);

namespace Macula;

/** A verified DHT record: its type, signer's key id (hex), times (unix
 * milliseconds), payload, and wire bytes (as BytesOutput renders them). */
final class DhtRecord
{
    public function __construct(
        public readonly int $type,
        public readonly string $keyId,
        public readonly int $createdAt,
        public readonly int $expiresAt,
        public readonly mixed $payload,
        public readonly mixed $wire,
    ) {
    }

    /**
     * @internal
     * @param array<string, mixed> $r
     */
    public static function fromArray(array $r): self
    {
        return new self($r['type'], $r['key_id'], $r['created_at'], $r['expires_at'], $r['payload'], $r['wire']);
    }
}
