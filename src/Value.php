<?php

declare(strict_types=1);

namespace Macula;

/**
 * A restricted mirror of macula-go's cbor.Value -- covers
 * Null/Int/Bytes/Text/Float, the same v1 cut macula-rust-ffi's own
 * FfiValue made (no List/Map yet -- recursive shapes are deliberately
 * deferred, not a wire limitation). A payload needing structure today
 * should be encoded as Bytes.
 *
 * The four kind constants match cabi/value.go's own tags exactly --
 * changing one side without the other silently misinterprets every
 * payload crossing the boundary.
 */
final class Value
{
    public const KIND_NULL = 0;
    public const KIND_INT = 1;
    public const KIND_BYTES = 2;
    public const KIND_TEXT = 3;
    public const KIND_FLOAT = 4;

    private function __construct(
        public readonly int $kind,
        public readonly int $intValue = 0,
        public readonly string $bytesValue = '',
        public readonly float $floatValue = 0.0,
    ) {
    }

    public static function null(): self
    {
        return new self(self::KIND_NULL);
    }

    public static function int(int $v): self
    {
        return new self(self::KIND_INT, intValue: $v);
    }

    public static function bytes(string $v): self
    {
        return new self(self::KIND_BYTES, bytesValue: $v);
    }

    public static function text(string $v): self
    {
        return new self(self::KIND_TEXT, bytesValue: $v);
    }

    public static function float(float $v): self
    {
        return new self(self::KIND_FLOAT, floatValue: $v);
    }

    public function isNull(): bool
    {
        return $this->kind === self::KIND_NULL;
    }

    public function asText(): string
    {
        return $this->bytesValue;
    }
}
