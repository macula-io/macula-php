<?php

declare(strict_types=1);

namespace Macula;

/** A call or stream that could not be kept confidential, so it was not made
 * or failed rather than go in the clear: `reason` as macula names it
 * ("no_kem_key", "seal_key_mismatch", ...), and the key ids it `named` and
 * `found`, as hex, when it has them. */
final class ConfidentialityError extends MaculaException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $named,
        public readonly ?string $found,
        string $message,
    ) {
        parent::__construct($message === '' ? "macula-php: confidentiality: {$reason}" : $message, 'confidentiality');
    }
}
