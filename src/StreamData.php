<?php

declare(strict_types=1);

namespace Macula;

/** A chunk: raw bytes (encoding "raw", body as BytesOutput renders bytes) or a
 * value (encoding "msgpack"). */
final class StreamData implements StreamEvent
{
    public function __construct(public readonly string $encoding, public readonly mixed $body)
    {
    }
}
