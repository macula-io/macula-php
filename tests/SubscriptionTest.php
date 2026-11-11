<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\BytesOutput;
use Macula\Event;
use Macula\MaculaException;
use Macula\Notice;
use Macula\Subscription;
use PHPUnit\Framework\TestCase;

/**
 * What a subscription hands back: an Event for a fact, and a Notice when the
 * library reports that events may have been missed.
 *
 * Every subscription item carries a `kind` (cabi/CONTRACT.md, "Inboxes"), and
 * a notice has none of an event's fields, so a reader that took every item for
 * an event threw a TypeError the first time a link was lost, and lost the gap
 * that says to resync the same way. The item is handed to next() itself here:
 * a lost link is the one thing a station cannot be asked to produce on
 * demand, and next() is where the item used to be misread.
 */
final class SubscriptionTest extends TestCase
{
    public function testALostLinkIsANoticeAndNotAnEvent(): void
    {
        $item = self::reading(['kind' => 'interrupted', 'from_ms' => 1_700_000_000_000, 'reason' => 'liveness_lost'])
            ->next(10);

        self::assertInstanceOf(Notice::class, $item, 'a notice was handed back as an event');
        self::assertSame(Notice::INTERRUPTED, $item->kind);
        self::assertSame(1_700_000_000_000, $item->fromMs);
        self::assertNull($item->toMs, 'an interruption names no time it was carried again');
        self::assertSame('liveness_lost', $item->reason);
    }

    public function testAGapIsANoticeNamingBothTimes(): void
    {
        $item = self::reading([
            'kind' => 'gap', 'from_ms' => 1_700_000_000_000, 'to_ms' => 1_700_000_060_000, 'reason' => 'goodbye',
        ])->next(10);

        self::assertInstanceOf(Notice::class, $item, 'a gap was handed back as an event');
        self::assertSame(Notice::GAP, $item->kind);
        self::assertSame(1_700_000_000_000, $item->fromMs);
        self::assertSame(1_700_000_060_000, $item->toMs);
        self::assertSame('goodbye', $item->reason);
    }

    public function testAnEventIsStillAnEvent(): void
    {
        $item = self::reading([
            'kind' => 'event',
            'publisher' => str_repeat('ab', 32),
            'realm' => str_repeat('cd', 32),
            'topic' => 'acme/demo/greeting_sent_v1',
            'seq' => 7,
            'published_at' => 1_700_000_000_123,
            'payload' => ['text' => 'hi'],
            'delivered_via' => 'direct',
        ])->next(10);

        self::assertInstanceOf(Event::class, $item, 'an event was not handed back as one');
        self::assertSame('acme/demo/greeting_sent_v1', $item->topic);
        self::assertSame(7, $item->seq);
        self::assertSame(['text' => 'hi'], $item->payload);
    }

    public function testAnItemOfAKindThisBindingDoesNotReadIsRefusedByName(): void
    {
        $this->expectException(MaculaException::class);
        $this->expectExceptionMessageMatches('/a subscription item of kind later_notice/');
        self::reading(['kind' => 'later_notice', 'from_ms' => 1])->next(10);
    }

    public function testEveryItemIsRoutedAndAnEmptyInboxEndsTheWait(): void
    {
        $items = [
            ['kind' => 'event', 'publisher' => str_repeat('ab', 32), 'realm' => str_repeat('cd', 32),
                'topic' => 'acme/demo/greeting_sent_v1', 'seq' => 1, 'published_at' => 2,
                'payload' => ['text' => 'hi'], 'delivered_via' => 'direct'],
            ['kind' => 'interrupted', 'from_ms' => 1_700_000_000_000, 'reason' => 'liveness_lost'],
        ];
        $sub = self::reading(...$items);

        $heard = iterator_to_array($sub->events(10), false);
        self::assertInstanceOf(Event::class, $heard[0], 'the event did not survive the walk');
        self::assertInstanceOf(Notice::class, $heard[1], 'the notice did not survive the walk');
        self::assertCount(2, $heard);
        self::assertNull($sub->next(10), 'an empty inbox does not end the wait with an item');
    }

    /** A subscription whose next() reads what the library would have handed
     * over, one item per call and then nothing.
     * @param array<string, mixed> ...$items */
    private static function reading(array ...$items): Subscription
    {
        $json = array_map(fn (array $item): string => json_encode($item, JSON_THROW_ON_ERROR), $items);
        $at = 0;
        return new Subscription(0, BytesOutput::Hex,
            function (int $timeoutMs) use ($json, &$at): array {
                $next = $json[$at] ?? null;
                $at++;
                return [$next, 0, $next === null];
            });
    }
}
