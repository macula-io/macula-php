<?php

declare(strict_types=1);

namespace Macula;

/** What the library refused or failed at, with its reason. Every error this
 * package throws for the mesh is one, or a subclass naming the kind. */
class MaculaException extends \RuntimeException
{
}
