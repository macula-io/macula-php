<?php

declare(strict_types=1);

namespace Macula;

/**
 * An event a subscription heard, verified. Ids are hex.
 *
 * An event heard under a sealed group (Pool::subscribeGroup) also names the
 * epoch it was sealed with, in $sealKeyId, and has one of two shapes:
 *
 *  - $unopened null: this node held the epoch key, so $payload is what the
 *    publisher sealed, and $sealKeyId is the id of the epoch that opened it;
 *  - $unopened a reason from GroupError's closed set: it could not be opened,
 *    $payload is null, and nothing of the plaintext is known.
 *
 * An event heard under a plain subscription has $sealed false, $sealKeyId
 * null and $unopened null; a sealed event reaching one is delivered with
 * $unopened set (GroupError::REASON_NO_GROUP, this node being under no group).
 */
final class Event
{
    public function __construct(
        public readonly string $publisher,
        public readonly string $realm,
        public readonly string $topic,
        public readonly int $seq,
        public readonly int $publishedAt,
        public readonly mixed $payload,
        public readonly string $deliveredVia,
        public readonly bool $sealed = false,
        public readonly ?string $sealKeyId = null,
        public readonly ?string $unopened = null,
    ) {
    }

    /** @internal */
    public static function fromJson(string $json, BytesOutput $bytes = BytesOutput::Hex): self
    {
        $e = Wire::decode($json);
        // A sealed event carries "sealed" 1 and its epoch id; "unopened" is
        // there only when it could not be opened. A clear event has none of
        // the three.
        return new self($e['publisher'], $e['realm'], $e['topic'], $e['seq'], $e['published_at'],
            Wire::output($e['payload'], $bytes), $e['delivered_via'],
            ($e['sealed'] ?? 0) === 1, $e['seal_key_id'] ?? null, $e['unopened'] ?? null);
    }
}
