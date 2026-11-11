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

    /**
     * One item a subscription handed over: an Event, or a Notice that events
     * may have been missed.
     *
     * Every subscription item carries a `kind` (CONTRACT.md, "Inboxes"):
     * "event", or one of the ABI's two notices, which carry none of an event's
     * fields and would be a TypeError out of Event::fromJson. A kind this
     * binding does not know is refused by name rather than read as an event.
     *
     * @internal
     */
    public static function item(string $json, BytesOutput $bytes): Event|Notice
    {
        $item = Wire::decode($json);
        $kind = is_array($item) ? ($item['kind'] ?? self::KIND_EVENT) : null;
        return match ($kind) {
            self::KIND_EVENT => Event::fromJson($json, $bytes),
            Notice::INTERRUPTED, Notice::GAP => Notice::fromItem($item),
            default => throw new MaculaException('macula-php: a subscription item of kind '
                . (is_string($kind) ? $kind : 'none') . ', which this binding does not read'),
        };
    }

    /** The kind an event carries; the only item that is a fact. */
    private const KIND_EVENT = 'event';
}
