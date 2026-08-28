<?php

declare(strict_types=1);

namespace Macula;

/**
 * A hint on a stream chunk for how to interpret its body -- not a
 * second wire codec. Mirrors frame.StreamEncoding.
 */
final class StreamEncoding
{
    /** body is opaque bytes. */
    public const RAW = 0;
    /** body is a structured Value. */
    public const MSGPACK = 1;
}
