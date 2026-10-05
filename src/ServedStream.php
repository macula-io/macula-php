<?php

declare(strict_types=1);

namespace Macula;

/**
 * A procedure served as a stream, until stop(). Its sessions wait until
 * next() or handle() takes them. A worker typically loops on handle().
 */
final class ServedStream
{
    private bool $stopped = false;
    private bool $ended = false;

    /** @internal */
    public function __construct(private readonly int $handle, private readonly BytesOutput $bytes)
    {
    }

    /** The next session, waiting at most timeoutMs; null when none arrived in
     * time or the procedure has ended (see ended()). The stream is the
     * caller's to end and free(). */
    public function next(int $timeoutMs = 1_000): ?Stream
    {
        if ($this->stopped || $this->ended) {
            return null;
        }
        [$json, $stream, $ended] = Inbox::take(fn ($wait, $handle, $closed, $err) =>
            Binding::ffi()->macula_served_next($this->handle, $wait, 0, $handle, $closed, $err), $timeoutMs);
        $this->ended = $ended;
        return $json === null ? null : new Stream($stream, $this->bytes);
    }

    /**
     * Takes the next session, waiting at most timeoutMs, and runs handler on
     * it. The stream is closed when the handler returns without ending it,
     * aborted with code "error" when it throws, and freed either way. Returns
     * whether there was a session.
     *
     * @param callable(Stream, Request): void $handler
     */
    public function handle(callable $handler, int $timeoutMs = 1_000): bool
    {
        $stream = $this->next($timeoutMs);
        if ($stream === null) {
            return false;
        }
        try {
            $handler($stream, $stream->request());
            try {
                $stream->close();
            } catch (MaculaException) {
            }
        } catch (\Throwable $e) {
            try {
                $stream->abort('error', $e->getMessage());
            } catch (MaculaException) {
            }
        } finally {
            $stream->free();
        }
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
}
