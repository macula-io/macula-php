<?php

declare(strict_types=1);

namespace Macula;

/** A call's result and its seal report (Pool::callReport). */
final class ReportedCall
{
    public function __construct(public readonly mixed $result, public readonly SealReport $report)
    {
    }
}
