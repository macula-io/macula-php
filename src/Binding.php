<?php

declare(strict_types=1);

namespace Macula;

/**
 * Loads libmacula, macula-go's released C ABI (abi/macula.h, the tag in
 * abi/MACULA_GO_REF), with PHP's FFI, and turns its conventions into PHP ones:
 * a function that fails sets a JSON error in its err_out, {"kind", "message",
 * ...}, which is thrown here as the MaculaException its kind names, and
 * freed. Internal to the package: the public API is NodeKey, Pool,
 * Subscription, Served, ServedStream and Stream.
 *
 * The declarations are abi/macula.h's, in plain C types: FFI::cdef() has no
 * preprocessor. Every call that blocks takes a cancel token, always 0 here.
 *
 * @internal
 */
final class Binding
{
    /** The ABI version this binding is written against. */
    public const ABI_VERSION = 1;

    private const CDEF = <<<'CDEF'
        int32_t macula_abi_version(void);
        void macula_free_string(char *s);
        void macula_free_bytes(uint8_t *b);

        uintptr_t macula_key_generate(const char *profile, uintptr_t cancel, char **err_out);
        uintptr_t macula_key_load(const char *path, const char *profile, char **err_out);
        uintptr_t macula_key_load_or_create(const char *path, const char *profile, uintptr_t cancel, char **err_out);
        void macula_key_save(uintptr_t key, const char *path, char **err_out);
        void macula_key_node_id(uintptr_t key, uint8_t *out_node_id, char **err_out);
        uint8_t *macula_key_public_key(uintptr_t key, size_t *out_len, char **err_out);
        char *macula_key_profile(uintptr_t key, char **err_out);
        uint8_t *macula_key_sign(uintptr_t key, const uint8_t *data, size_t data_len, size_t *out_len, char **err_out);
        int32_t macula_verify(const uint8_t *data, size_t data_len, const uint8_t *signature, size_t signature_len,
            const uint8_t *public_key, size_t public_key_len, const char *profile, char **err_out);
        void macula_key_free(uintptr_t key);

        uintptr_t macula_pool_connect(uintptr_t key, const char *seeds_json, const char *options_json, uintptr_t cancel,
            char **err_out);
        void macula_pool_close(uintptr_t pool);
        void macula_pool_node_id(uintptr_t pool, uint8_t *out_node_id, char **err_out);
        char *macula_pool_status(uintptr_t pool, char **err_out);

        char *macula_pool_call_opts(uintptr_t pool, const uint8_t *realm, const char *procedure, const char *payload_json,
            const char *options_json, int64_t timeout_ms, uintptr_t cancel, char **err_out);
        char *macula_pool_providers(uintptr_t pool, const uint8_t *realm, const char *procedure, int64_t timeout_ms,
            uintptr_t cancel, char **err_out);

        void macula_pool_publish(uintptr_t pool, const uint8_t *realm, const char *topic, const char *payload_json,
            int64_t ttl_ms, char **err_out);
        uintptr_t macula_pool_subscribe(uintptr_t pool, const uint8_t *realm, const char *topic, char **err_out);
        char *macula_subscription_next(uintptr_t subscription, int64_t timeout_ms, uintptr_t cancel, int32_t *closed,
            char **err_out);
        void macula_subscription_stop(uintptr_t subscription);

        uintptr_t macula_pool_serve_opts(uintptr_t pool, const uint8_t *realm, const char *procedure,
            const char *options_json, char **err_out);
        uintptr_t macula_pool_serve_stream_opts(uintptr_t pool, const uint8_t *realm, const char *procedure, int32_t mode,
            const char *options_json, char **err_out);
        char *macula_served_next(uintptr_t served, int64_t timeout_ms, uintptr_t cancel, uintptr_t *out_item,
            int32_t *closed, char **err_out);
        void macula_pending_reply(uintptr_t pending, const char *result_json, char **err_out);
        void macula_pending_error(uintptr_t pending, const char *message, char **err_out);
        void macula_served_stop(uintptr_t served, char **err_out);

        uintptr_t macula_pool_open_stream_opts(uintptr_t pool, const uint8_t *realm, const char *procedure, int32_t mode,
            const char *payload_json, const char *options_json, int64_t deadline_ms, int64_t timeout_ms,
            uintptr_t cancel, char **err_out);
        char *macula_stream_request(uintptr_t stream, char **err_out);
        void macula_stream_send_bytes(uintptr_t stream, const uint8_t *data, size_t data_len, char **err_out);
        void macula_stream_send_json(uintptr_t stream, const char *value_json, char **err_out);
        void macula_stream_close_send(uintptr_t stream, char **err_out);
        void macula_stream_reply(uintptr_t stream, const char *payload_json, char **err_out);
        void macula_stream_abort(uintptr_t stream, const char *code, const char *message, char **err_out);
        void macula_stream_close(uintptr_t stream, char **err_out);
        char *macula_stream_recv(uintptr_t stream, int64_t timeout_ms, uintptr_t cancel, char **err_out);
        void macula_stream_free(uintptr_t stream);

        void macula_pool_share_content(uintptr_t pool, const uint8_t *realm, const uint8_t *data, size_t data_len,
            const char *name, int64_t timeout_ms, uintptr_t cancel, uint8_t *out_mcid, char **err_out);
        void macula_pool_unshare_content(uintptr_t pool, const uint8_t *realm, const uint8_t *mcid, int64_t timeout_ms,
            uintptr_t cancel, char **err_out);
        uint8_t *macula_pool_get_content(uintptr_t pool, const uint8_t *realm, const uint8_t *mcid,
            const char *options_json, int64_t timeout_ms, uintptr_t cancel, size_t *out_len, char **err_out);

        char *macula_pool_find_record(uintptr_t pool, const uint8_t *key, int64_t timeout_ms, uintptr_t cancel,
            char **err_out);
        char *macula_pool_find_records(uintptr_t pool, const uint8_t *key, int64_t timeout_ms, uintptr_t cancel,
            char **err_out);
        char *macula_pool_find_records_by_type(uintptr_t pool, int32_t record_type, int64_t timeout_ms, uintptr_t cancel,
            char **err_out);
        void macula_pool_put_record(uintptr_t pool, const uint8_t *wire, size_t wire_len, int64_t timeout_ms,
            uintptr_t cancel, char **err_out);
        CDEF;

    private static ?\FFI $ffi = null;

    /**
     * The loaded library: MACULA_LIBRARY_PATH, or this platform's library in
     * build/native, which `composer build` fetches and checks. A library built
     * for another ABI version is refused.
     */
    public static function ffi(): \FFI
    {
        if (self::$ffi === null) {
            $path = getenv('MACULA_LIBRARY_PATH') ?: __DIR__ . '/../build/native/' . self::libraryName();
            if (!is_file($path)) {
                throw new MaculaException("libmacula not found at {$path}: fetch it with `composer build`"
                    . ' (or set MACULA_LIBRARY_PATH)');
            }
            $ffi = \FFI::cdef(self::CDEF, $path);
            $version = $ffi->macula_abi_version();
            if ($version !== self::ABI_VERSION) {
                throw new MaculaException("libmacula at {$path} is ABI {$version}; macula-php needs ABI "
                    . self::ABI_VERSION);
            }
            self::$ffi = $ffi;
        }
        return self::$ffi;
    }

    /** The released library's file name for this platform. */
    private static function libraryName(): string
    {
        $arm = in_array(php_uname('m'), ['aarch64', 'arm64'], true);
        return match (PHP_OS_FAMILY) {
            'Darwin' => $arm ? 'libmacula-macos-arm64.dylib' : 'libmacula-macos-x64.dylib',
            'Windows' => 'macula-windows-x64.dll',
            default => $arm ? 'libmacula-linux-arm64.so' : 'libmacula-linux-x64.so',
        };
    }

    /**
     * Calls fn with a fresh err_out, and throws what it set, as the
     * MaculaException its kind names, after freeing it.
     *
     * @template T
     * @param callable(\FFI\CData): T $fn
     * @return T
     */
    public static function call(callable $fn): mixed
    {
        $ffi = self::ffi();
        $err = $ffi->new('char*');
        $result = $fn(\FFI::addr($err));
        if (!\FFI::isNull($err)) {
            $text = \FFI::string($err);
            $ffi->macula_free_string($err);
            throw self::error($text);
        }
        return $result;
    }

    /** The library's JSON error as the class its kind names. */
    private static function error(string $text): MaculaException
    {
        $e = json_decode($text, true);
        if (!is_array($e) || !is_string($e['kind'] ?? null)) {
            return new MaculaException($text);
        }
        $message = (string) ($e['message'] ?? '');
        return match ($e['kind']) {
            'provider_error' => new ProviderError((string) ($e['code'] ?? ''), (string) ($e['detail'] ?? '')),
            'relay_error' => new RelayError((string) ($e['code'] ?? '')),
            'not_shared' => new NotSharedError(),
            'unavailable' => new ContentUnavailableError(implode('; ', $e['failures'] ?? [])),
            'confidentiality' => new ConfidentialityError((string) ($e['reason'] ?? ''), $e['named'] ?? null,
                $e['found'] ?? null, $message),
            default => new MaculaException($message, $e['kind']),
        };
    }

    /** A C string the library returned, as PHP text, freed. */
    public static function takeString(?\FFI\CData $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $text = \FFI::string($s);
        self::ffi()->macula_free_string($s);
        return $text;
    }

    /** A byte buffer the library returned, of length, as a PHP string, freed. */
    public static function takeBytes(?\FFI\CData $b, int $length): string
    {
        if ($b === null) {
            return '';
        }
        $bytes = \FFI::string($b, $length);
        self::ffi()->macula_free_bytes($b);
        return $bytes;
    }

    /** A PHP string as a C buffer of its bytes, owned by PHP. */
    public static function buffer(string $bytes): \FFI\CData
    {
        $n = max(1, strlen($bytes));
        $buf = self::ffi()->new("uint8_t[{$n}]");
        \FFI::memcpy($buf, $bytes, strlen($bytes));
        return $buf;
    }

    /** A fresh size_t out-parameter. */
    public static function size(): \FFI\CData
    {
        return self::ffi()->new('size_t');
    }

    /** A fresh int32_t out-parameter. */
    public static function int(): \FFI\CData
    {
        return self::ffi()->new('int32_t');
    }

    /** A fresh uintptr_t out-parameter. */
    public static function handle(): \FFI\CData
    {
        return self::ffi()->new('uintptr_t');
    }

    /** The 32 bytes a node_id out-parameter was filled with, as hex. */
    public static function id32Out(callable $fill): string
    {
        $out = self::ffi()->new('uint8_t[32]');
        self::call(static fn ($err) => $fill($out, $err));
        return bin2hex(\FFI::string($out, 32));
    }
}
