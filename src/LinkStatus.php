<?php

declare(strict_types=1);

namespace Macula;

/** One of the pool's links. */
final class LinkStatus
{
    public function __construct(
        public readonly string $station,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $direct,
        public readonly bool $up,
    ) {
    }
}
