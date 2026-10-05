<?php

declare(strict_types=1);

namespace Macula;

/**
 * Payloads, ids and errors as they cross to the library.
 *
 * A payload is what macula's wire CBOR carries: null, int, float, string,
 * list, map. There is no boolean: encode true/false as 1/0 yourself; a PHP
 * bool is refused before it reaches the wire. A PHP list is a CBOR list, an
 * array with string keys (or an object) a map; an empty map is `new
 * \stdClass()`, since `[]` is an empty list.
 *
 * Bytes have no JSON shape. Going in, give them as Wire::bytes($raw), the
 * tagged form `['$bytes' => base64]`; a plain string is always text. Coming
 * out, bytes are a "0x"-prefixed lowercase hex string by default, or the
 * tagged form when BytesOutput::Tagged is asked for. Only the tagged form
 * tells bytes from text that happens to start with "0x".
 */
final class Wire
{
    /** How long a call waits, in milliseconds, when not told: macula's 5 s. */
    public const DEFAULT_CALL_TIMEOUT_MS = 5_000;

    /** How long a whole content fetch waits when not told: 5 minutes. */
    public const DEFAULT_CONTENT_TIMEOUT_MS = 300_000;

    /** Raw bytes as a payload value. @return array{'$bytes': string} */
    public static function bytes(string $raw): array
    {
        return ['$bytes' => base64_encode($raw)];
    }

    /** @internal A payload as the JSON text the library takes. */
    public static function encode(mixed $payload): string
    {
        self::refuseBooleans($payload, 'payload');
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @internal JSON text from the library as PHP values, maps as arrays. */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /** @internal A 32-byte id given as 64 hex characters or 32 bytes, as bytes. */
    public static function id32(string $id, string $what = 'id'): string
    {
        if (strlen($id) === 64 && ctype_xdigit($id)) {
            return hex2bin($id);
        }
        if (strlen($id) === 32) {
            return $id;
        }
        throw new \InvalidArgumentException("macula-php: {$what} must be 64 hex characters or 32 bytes");
    }

    /** @internal A content id given as 100 hex characters or 50 bytes, as bytes. */
    public static function mcid50(string $mcid): string
    {
        if (strlen($mcid) === 100 && ctype_xdigit($mcid)) {
            return hex2bin($mcid);
        }
        if (strlen($mcid) === 50) {
            return $mcid;
        }
        throw new \InvalidArgumentException('macula-php: a content id is 100 hex characters or 50 bytes');
    }

    /**
     * @internal A value from the library as PHP gets it under bytes: the
     * library gives every byte string tagged, `['$bytes' => base64]`; Hex
     * turns each into "0x" and its lowercase hex.
     */
    public static function output(mixed $value, BytesOutput $bytes): mixed
    {
        if ($bytes === BytesOutput::Tagged || !is_array($value)) {
            return $value;
        }
        if (count($value) === 1 && is_string($value['$bytes'] ?? null)) {
            return '0x' . bin2hex(base64_decode($value['$bytes'], true));
        }
        return array_map(static fn (mixed $v) => self::output($v, $bytes), $value);
    }

    /** @internal Library JSON as PHP values under bytes. */
    public static function decodeOutput(string $json, BytesOutput $bytes): mixed
    {
        return self::output(self::decode($json), $bytes);
    }

    private static function refuseBooleans(mixed $value, string $path): void
    {
        if (is_bool($value)) {
            throw new \InvalidArgumentException("macula-php: {$path} is a boolean, which the wire does not carry:"
                . ' use 1 or 0');
        }
        if (is_array($value) || is_object($value)) {
            foreach ((array) $value as $key => $inner) {
                self::refuseBooleans($inner, "{$path}[{$key}]");
            }
        }
    }
}
