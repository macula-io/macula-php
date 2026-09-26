<?php

declare(strict_types=1);

namespace Macula;

/**
 * A served call taken from Served::next, to answer once with reply() or
 * fail() before its deadline. One not answered by then is answered for it
 * with an error, and answering it after is refused.
 */
final class PendingCall
{
    /** @internal */
    public function __construct(private readonly int $handle, public readonly Request $request)
    {
    }

    /** Answers the call with result. */
    public function reply(mixed $result): void
    {
        $json = Wire::encode($result);
        Binding::call(fn ($err) => Binding::ffi()->macula_pending_reply($this->handle, $json, $err));
    }

    /** Answers the call with a handler_error carrying message. */
    public function fail(string $message): void
    {
        Binding::call(fn ($err) => Binding::ffi()->macula_pending_error($this->handle, $message, $err));
    }
}
