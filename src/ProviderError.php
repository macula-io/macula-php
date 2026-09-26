<?php

declare(strict_types=1);

namespace Macula;

/**
 * A provider's own ERROR for a call: `handler_error` with the handler's text,
 * `temporary_relay_failure` for a handler that crashed, `unknown_next_peer`
 * for a procedure it does not serve, or an admission refusal (`expired`,
 * `request_copy`, `caller_quota`, ...).
 */
final class ProviderError extends MaculaException
{
    public function __construct(public readonly string $errorCode, public readonly string $detail)
    {
        parent::__construct("macula-php: the provider answered {$errorCode}" . ($detail === '' ? '' : ": {$detail}"));
    }
}
