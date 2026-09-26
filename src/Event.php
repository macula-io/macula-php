<?php

declare(strict_types=1);

namespace Macula;

/** An event a subscription heard, verified. Ids are hex. */
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
    ) {
    }

    /** @internal */
    public static function fromJson(string $json): self
    {
        $e = Wire::decode($json);
        return new self($e['publisher'], $e['realm'], $e['topic'], $e['seq'], $e['published_at'], $e['payload'],
            $e['delivered_via']);
    }
}
