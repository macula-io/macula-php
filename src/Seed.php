<?php

declare(strict_types=1);

namespace Macula;

/** A station to link to, pinned by the node_id it must prove. */
final class Seed
{
    /** @param string $nodeId 64 hex characters or 32 bytes */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $nodeId,
    ) {
    }
}
