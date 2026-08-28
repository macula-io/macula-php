<?php

declare(strict_types=1);

namespace Macula;

/** Who's expected to push data on a stream -- mirrors frame.StreamMode. */
final class StreamMode
{
    /** The provider pushes chunks at the caller. */
    public const SERVER_STREAM = 0;
    /** The caller pushes chunks at the provider. */
    public const CLIENT_STREAM = 1;
    /** Both directions. */
    public const BIDI = 2;
}
