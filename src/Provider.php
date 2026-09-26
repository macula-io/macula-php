<?php

declare(strict_types=1);

namespace Macula;

/** A trusted provider of a procedure (its node_id) and the station it serves
 * from, both as hex. */
final class Provider
{
    public function __construct(public readonly string $node, public readonly string $station)
    {
    }
}
