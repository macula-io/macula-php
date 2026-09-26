<?php

declare(strict_types=1);

namespace Macula;

/** The three stream modes: the provider sends (Server), the caller sends and
 * the provider replies (Client), or both send (Bidi). */
enum StreamMode: int
{
    case Server = 0;
    case Client = 1;
    case Bidi = 2;
}
