<?php

declare(strict_types=1);

namespace Macula;

/**
 * A subscription, until stop() or the pool closes. Its verified events wait
 * until next() takes them, in the order they were heard, and so does every
 * notice that events may have been missed (see Notice).
 */
final class Subscription
{
    private bool $stopped = false;
    private bool $ended = false;

    /**
     * @param \Closure(int): array{0: ?string, 1: int, 2: bool}|null $inbox the
     *   subscription's next item from the library, and the library's own when
     *   null: a test hands one over to prove what next() does with an item it
     *   has no station to lose (a notice)
     * @internal
     */
    public function __construct(
        private readonly int $handle,
        private readonly BytesOutput $bytes = BytesOutput::Hex,
        private readonly ?\Closure $inbox = null,
    ) {
    }

    /**
     * The next item, waiting at most timeoutMs; null when none arrived in time
     * or the subscription has ended (see ended()).
     *
     * An Event when a fact was published under the topic, or a Notice when the
     * library reports that events may have been missed: its link to the
     * station ended (Notice::INTERRUPTED), or a station carries it again and
     * what fell between the two times may be gone (Notice::GAP). A notice is
     * never handed back as an Event.
     */
    public function next(int $timeoutMs = 1_000): Event|Notice|null
    {
        if ($this->stopped || $this->ended) {
            return null;
        }
        [$json, , $ended] = $this->take($timeoutMs);
        $this->ended = $ended;
        return $json === null ? null : Inbox::item($json, $this->bytes);
    }

    /**
     * Every item as it arrives, until the subscription ends or none arrives
     * within idleMs. @return \Generator<int, Event|Notice>
     */
    public function events(int $idleMs = 1_000): \Generator
    {
        while (($item = $this->next($idleMs)) !== null) {
            yield $item;
        }
    }

    /** Whether the subscription has ended: stopped, or its pool closed. */
    public function ended(): bool
    {
        return $this->stopped || $this->ended;
    }

    /** Ends the subscription on every link. */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        Binding::ffi()->macula_subscription_stop($this->handle);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** @return array{0: ?string, 1: int, 2: bool} */
    private function take(int $timeoutMs): array
    {
        if ($this->inbox !== null) {
            return ($this->inbox)($timeoutMs);
        }
        return Inbox::take(fn ($wait, $_, $closed, $err) =>
            Binding::ffi()->macula_subscription_next($this->handle, $wait, 0, $closed, $err), $timeoutMs);
    }
}
