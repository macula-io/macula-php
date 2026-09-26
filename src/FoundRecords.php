<?php

declare(strict_types=1);

namespace Macula;

/** The verified records a lookup found, and how many did not verify. */
final class FoundRecords
{
    /** @param list<DhtRecord> $records */
    public function __construct(public readonly array $records, public readonly int $dropped)
    {
    }

    /** @internal */
    public static function fromJson(string $json): self
    {
        $out = Wire::decode($json);
        return new self(array_map(DhtRecord::fromArray(...), $out['records'] ?? []), $out['dropped'] ?? 0);
    }
}
