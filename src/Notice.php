<?php

declare(strict_types=1);

namespace Macula;

/**
 * What a subscription reports when its events may have been missed: the last
 * station link carrying it ended (INTERRUPTED, from when that link last heard
 * from its station, for $reason), and later a station carries it again (GAP,
 * and what fell between $fromMs and $toMs may be gone, so resync).
 *
 * Every subscription item carries a `kind` (cabi/CONTRACT.md, "Inboxes"), and
 * a notice has none of an event's fields. A reader that took every item for an
 * event made one of a notice, with every field null; Subscription::next
 * returns the notice instead, so a lost link is never mistaken for an event
 * and a gap is never lost. A notice arrives only where no other link carried
 * the subscription through, and never at the first subscribe.
 */
final class Notice
{
    /** The subscription has no link to a station: events from $fromMs on may
     * be missed until a GAP follows. */
    public const INTERRUPTED = 'interrupted';

    /** A station carries the subscription again. What fell between $fromMs and
     * $toMs may be gone. */
    public const GAP = 'gap';

    /**
     * @param string $kind INTERRUPTED or GAP
     * @param int $fromMs when the link last heard from its station (this
     *   node's clock: a hint, not a filter on an event's publishedAt)
     * @param int|null $toMs for a GAP, when it was carried again; null for an
     *   INTERRUPTED
     * @param string $reason the library's own word: `liveness_lost`,
     *   `closed_by_station`, `goodbye`, ...
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $fromMs,
        public readonly ?int $toMs,
        public readonly string $reason,
    ) {
    }

    /**
     * A notice as the ABI writes it, e.g. {"kind":"gap","from_ms":1,"to_ms":2,
     * "reason":"liveness_lost"}. Ids and times are the library's; nothing is
     * mapped but the field names.
     *
     * @param array<string, mixed> $item
     * @internal
     */
    public static function fromItem(array $item): self
    {
        return new self((string) $item['kind'], (int) $item['from_ms'],
            isset($item['to_ms']) ? (int) $item['to_ms'] : null, (string) $item['reason']);
    }
}
