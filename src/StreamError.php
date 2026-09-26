<?php

declare(strict_types=1);

namespace Macula;

/** A stream ended by a STREAM_ERROR: the peer's, a relay error from the
 * station (`relay`), or this side's own (e.g. `resource_exhausted`). */
final class StreamError extends MaculaException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $detail,
        public readonly bool $relay,
    ) {
        parent::__construct("macula-php: stream error {$errorCode}" . ($detail === '' ? '' : ": {$detail}"));
    }
}
