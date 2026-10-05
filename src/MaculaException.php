<?php

declare(strict_types=1);

namespace Macula;

/** What the library refused or failed at, with its reason. Every error this
 * package throws for the mesh is one, or a subclass naming the kind. `kind`
 * is the library's error kind (cabi/CONTRACT.md in macula-go): "timeout",
 * "not_found", "no_provider", "refused", "closed", "failed" and the rest. */
class MaculaException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'failed')
    {
        parent::__construct($message);
    }
}
