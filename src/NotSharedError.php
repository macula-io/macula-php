<?php

declare(strict_types=1);

namespace Macula;

/** Content no node announces in the realm. */
final class NotSharedError extends MaculaException
{
    public function __construct()
    {
        parent::__construct('macula-php: no node shares that content in that realm', 'not_shared');
    }
}
