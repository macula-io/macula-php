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
 * tagged form when BytesOutput::Tagged is asked for.
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
     * @internal The library's call error as the class it names:
     * "provider_error:<code>:<detail>" and "relay_error:<code>", any other
     * text a MaculaException.
     */
    public static function callError(string $message): MaculaException
    {
        if (preg_match('/^provider_error:([^:]*):(.*)$/s', $message, $m) === 1) {
            return new ProviderError($m[1], $m[2]);
        }
        if (preg_match('/^relay_error:(.*)$/s', $message, $m) === 1) {
            return new RelayError($m[1]);
        }
        return new MaculaException($message);
    }

    /** @internal The library's content errors as the classes they name. */
    public static function contentError(string $message): MaculaException
    {
        if ($message === 'not_shared') {
            return new NotSharedError();
        }
        if (str_starts_with($message, 'unavailable:')) {
            return new ContentUnavailableError(substr($message, strlen('unavailable:')));
        }
        return new MaculaException($message);
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
