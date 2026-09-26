<?php

declare(strict_types=1);

namespace Macula;

/**
 * @internal What a subscription or a served procedure hands over: PHP takes
 * no callback on a Go thread, so events, calls and sessions wait in an inbox
 * in the library until a *_next call takes the next one.
 */
final class Inbox
{
    /**
     * The next item's JSON and handle; null when nothing arrived within
     * timeoutMs, and ended set when the inbox has ended and holds no more.
     *
     * @param callable(int, \FFI\CData, \FFI\CData, \FFI\CData): ?\FFI\CData $next
     * @return array{0: ?string, 1: int, 2: bool}
     */
    public static function take(callable $next, int $timeoutMs): array
    {
        $handle = Binding::handle();
        $closed = Binding::int();
        $json = Binding::call(fn ($err) => $next(max(0, $timeoutMs), \FFI::addr($handle), \FFI::addr($closed), $err));
        return [Binding::takeString($json), $handle->cdata, $closed->cdata !== 0];
    }
}
