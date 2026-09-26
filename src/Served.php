<?php

declare(strict_types=1);

namespace Macula;

/**
 * A served procedure, until stop(). Its calls wait until next() or handle()
 * takes them; a call nobody takes by its deadline is answered for it with an
 * error. A worker typically loops on handle().
 */
final class Served
{
    private bool $stopped = false;
    private bool $ended = false;

    /** @internal */
    public function __construct(private readonly int $handle)
    {
    }

    /** The next call, waiting at most timeoutMs; null when none arrived in
     * time or the procedure has ended (see ended()). */
    public function next(int $timeoutMs = 1_000): ?PendingCall
    {
        if ($this->stopped || $this->ended) {
            return null;
        }
        [$json, $call, $ended] = Inbox::take(fn ($wait, $handle, $closed, $err) =>
            Binding::ffi()->macula_served_next($this->handle, $wait, $handle, $closed, $err), $timeoutMs);
        $this->ended = $ended;
        return $json === null ? null : new PendingCall($call, Request::fromJson($json));
    }

    /**
     * Takes the next call, waiting at most timeoutMs, and answers it with what
     * handler returns, or a handler_error with the message of what it throws.
     * Returns whether there was a call.
     *
     * @param callable(Request): mixed $handler
     */
    public function handle(callable $handler, int $timeoutMs = 1_000): bool
    {
        $call = $this->next($timeoutMs);
        if ($call === null) {
            return false;
        }
        try {
            $result = $handler($call->request);
        } catch (\Throwable $e) {
            self::quietly(fn () => $call->fail($e->getMessage()));
            return true;
        }
        self::quietly(fn () => $call->reply($result));
        return true;
    }

    /** Whether the procedure has ended: stopped, or its pool closed. */
    public function ended(): bool
    {
        return $this->stopped || $this->ended;
    }

    /** Withdraws the procedure on every link. */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        Binding::call(fn ($err) => Binding::ffi()->macula_served_stop($this->handle, $err));
    }

    public function __destruct()
    {
        if (!$this->stopped) {
            $this->stopped = true;
            Binding::ffi()->macula_served_stop($this->handle, null);
        }
    }

    /** Answers, where an answer refused means the call's deadline passed and
     * nothing waits for it any more. */
    private static function quietly(callable $answer): void
    {
        try {
            $answer();
        } catch (MaculaException) {
        }
    }
}
