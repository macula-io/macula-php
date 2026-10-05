<?php

declare(strict_types=1);

namespace Macula;

/** Content every announcing node failed to give; `detail` names each failure
 * (unreachable, not the content asked for, over the bounds, ...). */
final class ContentUnavailableError extends MaculaException
{
    public function __construct(public readonly string $detail)
    {
        parent::__construct("macula-php: no sharer gave the content: {$detail}", 'unavailable');
    }
}
