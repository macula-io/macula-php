<?php

declare(strict_types=1);

namespace Macula;

/**
 * Loads libmacula.so (cabi/, macula-go's pool behind a C ABI) with PHP's FFI,
 * and turns its conventions into PHP ones: a function that fails sets a C
 * string in its err_out, which is thrown here as a MaculaException (or the
 * subclass its text names) and freed. Internal to the package: the public API
 * is NodeKey, Pool, Subscription, Served, ServedStream and Stream.
 *
 * The declarations are written out rather than read from the generated
 * header: FFI::cdef() has no preprocessor, so cgo's boilerplate cannot be fed
 * to it. They are the functions cabi/ exports, in plain C types.
 *
 * @internal
 */
final class Binding
{
    private const CDEF = <<<'CDEF'
        void macula_free_string(char *s);
        void macula_free_bytes(unsigned char *b);

        uintptr_t macula_key_generate(char *profile, char **err_out);
        uintptr_t macula_key_load(char *path, char *profile, char **err_out);
        void macula_key_save(uintptr_t h, char *path, char **err_out);
        void macula_key_node_id(uintptr_t h, unsigned char *out32, char **err_out);
        unsigned char *macula_key_public_key(uintptr_t h, size_t *out_len, char **err_out);
        char *macula_key_profile(uintptr_t h, char **err_out);
        unsigned char *macula_key_sign(uintptr_t h, unsigned char *data, size_t data_len, size_t *out_len, char **err_out);
        void macula_key_free(uintptr_t h);
        int macula_verify(unsigned char *data, size_t data_len, unsigned char *signature, size_t signature_len,
            unsigned char *public_key, size_t public_key_len, char *profile, char **err_out);

        uintptr_t macula_pool_connect(uintptr_t key, char *seeds_json, char *opts_json, char **err_out);
        void macula_pool_close(uintptr_t h);
        void macula_pool_node_id(uintptr_t h, unsigned char *out32, char **err_out);
        char *macula_pool_status(uintptr_t h, char **err_out);
        char *macula_pool_call(uintptr_t h, unsigned char *realm32, char *procedure, char *payload_json,
            unsigned char *provider32, int64_t timeout_ms, int bytes_mode, char **err_out);
        char *macula_pool_providers(uintptr_t h, unsigned char *realm32, char *procedure, int64_t timeout_ms, char **err_out);
        void macula_pool_publish(uintptr_t h, unsigned char *realm32, char *topic, char *payload_json, int64_t ttl_ms,
            char **err_out);
        uintptr_t macula_pool_subscribe(uintptr_t h, unsigned char *realm32, char *topic, int bytes_mode, char **err_out);
        char *macula_subscription_next(uintptr_t h, int timeout_ms, int *closed, char **err_out);
        void macula_subscription_stop(uintptr_t h);
        char *macula_pool_find_record(uintptr_t h, unsigned char *key32, int64_t timeout_ms, int bytes_mode, char **err_out);
        char *macula_pool_find_records(uintptr_t h, unsigned char *key32, int64_t timeout_ms, int bytes_mode, char **err_out);
        char *macula_pool_find_records_by_type(uintptr_t h, int record_type, int64_t timeout_ms, int bytes_mode,
            char **err_out);
        void macula_pool_put_record(uintptr_t h, unsigned char *wire, size_t wire_len, int64_t timeout_ms, char **err_out);

        uintptr_t macula_pool_serve(uintptr_t h, unsigned char *realm32, char *procedure, int bytes_mode, char **err_out);
        char *macula_served_next(uintptr_t h, int timeout_ms, uintptr_t *handle, int *closed, char **err_out);
        void macula_pending_reply(uintptr_t h, char *result_json, char **err_out);
        void macula_pending_error(uintptr_t h, char *message, char **err_out);
        void macula_served_stop(uintptr_t h, char **err_out);
        uintptr_t macula_pool_serve_stream(uintptr_t h, unsigned char *realm32, char *procedure, int mode, int bytes_mode,
            char **err_out);

        uintptr_t macula_pool_open_stream(uintptr_t h, unsigned char *realm32, char *procedure, int mode, char *payload_json,
            unsigned char *provider32, int64_t deadline_ms, int64_t timeout_ms, char **err_out);
        void macula_stream_send_bytes(uintptr_t h, unsigned char *data, size_t data_len, char **err_out);
        void macula_stream_send_json(uintptr_t h, char *value_json, char **err_out);
        void macula_stream_close_send(uintptr_t h, char **err_out);
        void macula_stream_close(uintptr_t h, char **err_out);
        void macula_stream_reply(uintptr_t h, char *payload_json, char **err_out);
        void macula_stream_abort(uintptr_t h, char *code, char *message, char **err_out);
        char *macula_stream_recv(uintptr_t h, int64_t timeout_ms, int bytes_mode, char **err_out);
        char *macula_stream_request(uintptr_t h, int bytes_mode, char **err_out);
        void macula_stream_free(uintptr_t h);

        unsigned char *macula_pool_share_content(uintptr_t h, unsigned char *realm32, unsigned char *data, size_t data_len,
            char *name, int64_t timeout_ms, size_t *out_len, char **err_out);
        void macula_pool_unshare_content(uintptr_t h, unsigned char *realm32, unsigned char *mcid, size_t mcid_len,
            int64_t timeout_ms, char **err_out);
        unsigned char *macula_pool_get_content(uintptr_t h, unsigned char *realm32, unsigned char *mcid, size_t mcid_len,
            uint64_t max_bytes, int max_chunks, int parallel, int64_t chunk_timeout_ms, int64_t timeout_ms,
            size_t *out_len, char **err_out);
        CDEF;

    private static ?\FFI $ffi = null;

    /** The loaded library: MACULA_LIBRARY_PATH, or cabi/libmacula.so. */
    public static function ffi(): \FFI
    {
        if (self::$ffi === null) {
            $path = getenv('MACULA_LIBRARY_PATH') ?: __DIR__ . '/../cabi/libmacula.so';
            if (!is_file($path)) {
                throw new MaculaException("libmacula.so not found at {$path}: build it with `composer build`"
                    . ' (or set MACULA_LIBRARY_PATH)');
            }
            self::$ffi = \FFI::cdef(self::CDEF, $path);
        }
        return self::$ffi;
    }

    /**
     * Calls fn with a fresh err_out, and throws what it set, as the error
     * class errorFor picks, after freeing it.
     *
     * @template T
     * @param callable(\FFI\CData): T $fn
     * @param callable(string): \Throwable $errorFor
     * @return T
     */
    public static function call(callable $fn, ?callable $errorFor = null): mixed
    {
        $ffi = self::ffi();
        $err = $ffi->new('char*');
        $result = $fn(\FFI::addr($err));
        if (!\FFI::isNull($err)) {
            $message = \FFI::string($err);
            $ffi->macula_free_string($err);
            throw ($errorFor ?? static fn (string $m) => new MaculaException($m))($message);
        }
        return $result;
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
        $buf = self::ffi()->new("unsigned char[{$n}]");
        \FFI::memcpy($buf, $bytes, strlen($bytes));
        return $buf;
    }

    /** A fresh size_t out-parameter. */
    public static function size(): \FFI\CData
    {
        return self::ffi()->new('size_t');
    }

    /** A fresh int out-parameter. */
    public static function int(): \FFI\CData
    {
        return self::ffi()->new('int');
    }

    /** A fresh uintptr_t out-parameter. */
    public static function handle(): \FFI\CData
    {
        return self::ffi()->new('uintptr_t');
    }

    /** The 32 bytes a node_id out-parameter was filled with, as hex. */
    public static function id32Out(callable $fill): string
    {
        $out = self::ffi()->new('unsigned char[32]');
        self::call(static fn ($err) => $fill($out, $err));
        return bin2hex(\FFI::string($out, 32));
    }
}
