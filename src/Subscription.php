<?php

declare(strict_types=1);

namespace Macula;

/**
 * A subscription, until stop() or the pool closes. Its verified events wait
 * until next() takes them, in the order they were heard.
 */
final class Subscription
{
    private bool $stopped = false;
    private bool $ended = false;

    /** @internal */
    public function __construct(private readonly int $handle, private readonly BytesOutput $bytes = BytesOutput::Hex)
    {
    }

    /** The next event, waiting at most timeoutMs; null when none arrived in
     * time or the subscription has ended (see ended()). */
    public function next(int $timeoutMs = 1_000): ?Event
    {
        if ($this->stopped || $this->ended) {
            return null;
        }
        [$json, , $ended] = Inbox::take(fn ($wait, $_, $closed, $err) =>
            Binding::ffi()->macula_subscription_next($this->handle, $wait, 0, $closed, $err), $timeoutMs);
        $this->ended = $ended;
        return $json === null ? null : Event::fromJson($json, $this->bytes);
    }

    /** Every event as it arrives, until the subscription ends or none arrives
     * within idleMs. @return \Generator<int, Event> */
    public function events(int $idleMs = 1_000): \Generator
    {
        while (($event = $this->next($idleMs)) !== null) {
            yield $event;
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
}
