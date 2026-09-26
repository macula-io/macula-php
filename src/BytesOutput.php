<?php

declare(strict_types=1);

namespace Macula;

/** How bytes in a result, a request, an event or a record reach PHP: a
 * "0x"-prefixed lowercase hex string (Hex, the default), or the tagged form
 * `['$bytes' => '<base64>']` (Tagged). */
enum BytesOutput: int
{
    case Hex = 0;
    case Tagged = 1;
}
