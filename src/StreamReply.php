<?php

declare(strict_types=1);

namespace Macula;

/** The provider's terminal value. */
final class StreamReply implements StreamEvent
{
    public function __construct(public readonly mixed $payload)
    {
    }
}
