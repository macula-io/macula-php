<?php

declare(strict_types=1);

namespace Macula;

/** A served call's request, or a stream's open: who sent it, where, its
 * payload, and its deadline (unix milliseconds). Ids are hex. */
final class Request
{
    public function __construct(
        public readonly string $caller,
        public readonly string $realm,
        public readonly string $procedure,
        public readonly mixed $payload,
        public readonly int $deadlineMs,
    ) {
    }

    /** @internal */
    public static function fromJson(string $json): self
    {
        $r = Wire::decode($json);
        return new self($r['caller'], $r['realm'], $r['procedure'], $r['payload'], $r['deadline_ms']);
    }
}
