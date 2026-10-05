<?php

declare(strict_types=1);

namespace Macula;

/** A station's signed relay error for a call: it could not relay it, e.g.
 * `unknown_next_peer` for a provider it cannot reach. */
final class RelayError extends MaculaException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("macula-php: the station could not relay the call: {$errorCode}", 'relay_error');
    }
}
