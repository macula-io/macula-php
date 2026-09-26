<?php

declare(strict_types=1);

namespace Macula;

/** The end of the peer's sending ("send") or of the whole stream ("both"). */
final class StreamEnd implements StreamEvent
{
    public function __construct(public readonly string $role)
    {
    }
}
