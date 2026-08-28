<?php

declare(strict_types=1);

namespace Macula;

/**
 * The terminal result of a client_stream/bidi exchange -- what
 * StreamHandle::awaitReply() hands back.
 */
final class StreamReply
{
    private ?int $handle;

    /** @internal */
    public function __construct(int $handle)
    {
        $this->handle = $handle;
    }

    public function payload(): Value
    {
        $ffi = Binding::get();
        $h = $this->handleOrFail();
        return Binding::valueFromParts(
            $ffi->macula_stream_reply_kind($h),
            $ffi->macula_stream_reply_int($h),
            Binding::readVarBytes(
                fn () => $ffi->macula_stream_reply_bytes_len($h),
                fn ($out) => $ffi->macula_stream_reply_bytes($h, $out),
            ),
            $ffi->macula_stream_reply_float($h),
        );
    }

    public function respondedBy(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_stream_reply_responded_by($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this StreamReply has already been freed');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_stream_reply_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
