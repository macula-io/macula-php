<?php

declare(strict_types=1);

namespace Macula;

/**
 * A PubSub delivery -- what Session::recvEvent() hands back. Wraps an
 * opaque handle into macula-go's frame.EventInfo.
 */
final class Event
{
    private ?int $handle;

    /** @internal */
    public function __construct(int $handle)
    {
        $this->handle = $handle;
    }

    public function topic(): string
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_event_topic($this->handleOrFail());
        $s = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return $s;
    }

    public function realm(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_event_realm($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    public function publisher(): string
    {
        $ffi = Binding::get();
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_event_publisher($this->handleOrFail(), $buf);
        return Binding::readBytes32($buf);
    }

    public function seq(): int
    {
        return Binding::get()->macula_event_seq($this->handleOrFail());
    }

    /** One of "direct", "plumtree", or "dht" -- how this event reached us. */
    public function deliveredVia(): string
    {
        $ffi = Binding::get();
        $ptr = $ffi->macula_event_delivered_via($this->handleOrFail());
        $s = \FFI::string($ptr);
        $ffi->macula_free_string($ptr);
        return $s;
    }

    public function payload(): Value
    {
        $ffi = Binding::get();
        $h = $this->handleOrFail();
        return Binding::valueFromParts(
            $ffi->macula_event_payload_kind($h),
            $ffi->macula_event_payload_int($h),
            Binding::readVarBytes(
                fn () => $ffi->macula_event_payload_bytes_len($h),
                fn ($out) => $ffi->macula_event_payload_bytes($h, $out),
            ),
            $ffi->macula_event_payload_float($h),
        );
    }

    private function handleOrFail(): int
    {
        if ($this->handle === null) {
            throw new \RuntimeException('this Event has already been freed');
        }
        return $this->handle;
    }

    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_event_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
