<?php

declare(strict_types=1);

namespace Macula;

/**
 * Loads libmacula.so via ext-ffi and exposes its C ABI. Not part of the
 * public API -- KeyPair and Session wrap this.
 *
 * The declarations below are hand-written, not the raw cgo-generated
 * header: FFI::cdef() has no C preprocessor (no #include, no #ifdef),
 * so the generated header's Go-specific typedefs and conditional
 * boilerplate can't be fed to it directly. These are the same
 * functions cabi/libmacula.h declares, in plain C types FFI
 * understands natively.
 */
final class Binding
{
    private static ?\FFI $ffi = null;

    public static function get(): \FFI
    {
        if (self::$ffi === null) {
            $libPath = getenv('MACULA_LIBRARY_PATH') ?: (__DIR__ . '/../cabi/libmacula.so');
            if (!is_file($libPath)) {
                throw new \RuntimeException(
                    "libmacula.so not found at {$libPath} -- build it first: " .
                    "cd cabi && go build -buildmode=c-shared -o libmacula.so . " .
                    "(or set MACULA_LIBRARY_PATH)"
                );
            }

            self::$ffi = \FFI::cdef(<<<'CDEF'
                uintptr_t macula_identity_generate(char **err_out);
                int macula_identity_node_id(uintptr_t identity_handle, unsigned char *out32);
                uintptr_t macula_identity_from_seed_bytes(unsigned char *seed32, char **err_out);
                int macula_identity_private_bytes(uintptr_t identity_handle, unsigned char *out32);
                void macula_identity_free(uintptr_t identity_handle);
                uintptr_t macula_connect(char *host, uint16_t port, uintptr_t identity_handle, int timeout_ms, char **err_out);
                uintptr_t macula_connect_seeds(char *seeds_csv, uintptr_t identity_handle, int timeout_ms, char **err_out);
                int macula_session_accepted(uintptr_t session_handle);
                int macula_session_station_node_id(uintptr_t session_handle, unsigned char *out32);
                void macula_session_close(uintptr_t session_handle, uintptr_t identity_handle);
                void macula_free_string(char *s);

                uintptr_t macula_call(uintptr_t session_handle, char *procedure, unsigned char *realm32,
                    int payload_kind, long long payload_int, unsigned char *payload_bytes, int payload_bytes_len, double payload_float,
                    int timeout_ms, uintptr_t identity_handle, char **err_out);
                int macula_response_is_error(uintptr_t response_handle);
                int macula_response_result_kind(uintptr_t response_handle);
                long long macula_response_result_int(uintptr_t response_handle);
                double macula_response_result_float(uintptr_t response_handle);
                int macula_response_result_bytes_len(uintptr_t response_handle);
                void macula_response_result_bytes(uintptr_t response_handle, unsigned char *out);
                void macula_response_responded_by(uintptr_t response_handle, unsigned char *out32);
                int macula_response_error_code(uintptr_t response_handle);
                char *macula_response_error_name(uintptr_t response_handle);
                void macula_response_reported_by(uintptr_t response_handle, unsigned char *out32);
                char *macula_response_error_detail(uintptr_t response_handle);
                void macula_response_free(uintptr_t response_handle);

                void macula_publish(uintptr_t session_handle, char *topic, unsigned char *realm32, unsigned long long seq,
                    int payload_kind, long long payload_int, unsigned char *payload_bytes, int payload_bytes_len, double payload_float,
                    long long published_at_ms, uintptr_t identity_handle, char **err_out);
                void macula_subscribe(uintptr_t session_handle, char *topic, unsigned char *realm32, uintptr_t identity_handle, char **err_out);
                void macula_unsubscribe(uintptr_t session_handle, char *topic, unsigned char *realm32, uintptr_t identity_handle, char **err_out);
                void macula_advertise(uintptr_t session_handle, char *procedure, unsigned char *realm32, uintptr_t identity_handle, char **err_out);
                void macula_unadvertise(uintptr_t session_handle, char *procedure, unsigned char *realm32, uintptr_t identity_handle, char **err_out);
                uintptr_t macula_recv_event(uintptr_t session_handle, int timeout_ms, char **err_out);
                char *macula_event_topic(uintptr_t event_handle);
                void macula_event_realm(uintptr_t event_handle, unsigned char *out32);
                void macula_event_publisher(uintptr_t event_handle, unsigned char *out32);
                unsigned long long macula_event_seq(uintptr_t event_handle);
                char *macula_event_delivered_via(uintptr_t event_handle);
                int macula_event_payload_kind(uintptr_t event_handle);
                long long macula_event_payload_int(uintptr_t event_handle);
                double macula_event_payload_float(uintptr_t event_handle);
                int macula_event_payload_bytes_len(uintptr_t event_handle);
                void macula_event_payload_bytes(uintptr_t event_handle, unsigned char *out);
                void macula_event_free(uintptr_t event_handle);

                int macula_content_put(uintptr_t session_handle, unsigned char *data, int data_len, char *name,
                    uintptr_t identity_handle, unsigned char *mcid_out, char **err_out);
                uintptr_t macula_content_get(uintptr_t session_handle, unsigned char *mcid34, uintptr_t identity_handle, char **err_out);
                int macula_bytes_handle_len(uintptr_t bytes_handle);
                void macula_bytes_handle_read(uintptr_t bytes_handle, unsigned char *out);
                void macula_bytes_handle_free(uintptr_t bytes_handle);

                uintptr_t macula_stream_open(uintptr_t session_handle, char *procedure, unsigned char *realm32, int mode,
                    int args_kind, long long args_int, unsigned char *args_bytes, int args_bytes_len, double args_float,
                    long long deadline_ms, uintptr_t identity_handle, char **err_out);
                uintptr_t macula_stream_accept(uintptr_t session_handle, int timeout_ms, uintptr_t *open_info_handle_out, char **err_out);
                char *macula_stream_open_info_procedure(uintptr_t info_handle);
                void macula_stream_open_info_realm(uintptr_t info_handle, unsigned char *out32);
                int macula_stream_open_info_mode(uintptr_t info_handle);
                int macula_stream_open_info_args_kind(uintptr_t info_handle);
                long long macula_stream_open_info_args_int(uintptr_t info_handle);
                double macula_stream_open_info_args_float(uintptr_t info_handle);
                int macula_stream_open_info_args_bytes_len(uintptr_t info_handle);
                void macula_stream_open_info_args_bytes(uintptr_t info_handle, unsigned char *out);
                long long macula_stream_open_info_deadline_ms(uintptr_t info_handle);
                void macula_stream_open_info_caller(uintptr_t info_handle, unsigned char *out32);
                void macula_stream_open_info_free(uintptr_t info_handle);

                void macula_stream_send_data(uintptr_t stream_handle, int encoding,
                    int body_kind, long long body_int, unsigned char *body_bytes, int body_bytes_len, double body_float,
                    uintptr_t identity_handle, char **err_out);
                void macula_stream_close_send(uintptr_t stream_handle, uintptr_t identity_handle, char **err_out);
                void macula_stream_send_reply(uintptr_t stream_handle,
                    int payload_kind, long long payload_int, unsigned char *payload_bytes, int payload_bytes_len, double payload_float,
                    uintptr_t identity_handle, char **err_out);
                uintptr_t macula_stream_recv(uintptr_t stream_handle, int timeout_ms, char **err_out);
                int macula_stream_item_is_eof(uintptr_t item_handle);
                unsigned long long macula_stream_item_seq(uintptr_t item_handle);
                int macula_stream_item_encoding(uintptr_t item_handle);
                int macula_stream_item_body_kind(uintptr_t item_handle);
                long long macula_stream_item_body_int(uintptr_t item_handle);
                double macula_stream_item_body_float(uintptr_t item_handle);
                int macula_stream_item_body_bytes_len(uintptr_t item_handle);
                void macula_stream_item_body_bytes(uintptr_t item_handle, unsigned char *out);
                void macula_stream_item_free(uintptr_t item_handle);
                uintptr_t macula_stream_await_reply(uintptr_t stream_handle, int timeout_ms, char **err_out);
                int macula_stream_reply_kind(uintptr_t reply_handle);
                long long macula_stream_reply_int(uintptr_t reply_handle);
                double macula_stream_reply_float(uintptr_t reply_handle);
                int macula_stream_reply_bytes_len(uintptr_t reply_handle);
                void macula_stream_reply_bytes(uintptr_t reply_handle, unsigned char *out);
                void macula_stream_reply_responded_by(uintptr_t reply_handle, unsigned char *out32);
                void macula_stream_reply_free(uintptr_t reply_handle);
                void macula_stream_abort(uintptr_t stream_handle, char *code, char *message, uintptr_t identity_handle);
                void macula_stream_free(uintptr_t stream_handle);

                uintptr_t macula_serve_wait_for_call(uintptr_t session_handle, uintptr_t identity_handle, int timeout_ms, char **err_out);
                char *macula_pending_call_procedure(uintptr_t pending_handle);
                void macula_pending_call_realm(uintptr_t pending_handle, unsigned char *out32);
                int macula_pending_call_payload_kind(uintptr_t pending_handle);
                long long macula_pending_call_payload_int(uintptr_t pending_handle);
                double macula_pending_call_payload_float(uintptr_t pending_handle);
                int macula_pending_call_payload_bytes_len(uintptr_t pending_handle);
                void macula_pending_call_payload_bytes(uintptr_t pending_handle, unsigned char *out);
                void macula_pending_call_reply_result(uintptr_t pending_handle,
                    int kind, long long int_val, unsigned char *bytes_val, int bytes_len, double float_val, char **err_out);
                void macula_pending_call_reply_error(uintptr_t pending_handle, char *detail, char **err_out);
                void macula_pending_call_free(uintptr_t pending_handle);

                char *macula_resolve_direct(uintptr_t session_handle, char *procedure, unsigned char *realm32,
                    uintptr_t identity_handle, unsigned char *station_out, uint16_t *port_out, char **err_out);
                char *macula_resolve_direct_with_cert_chain(uintptr_t session_handle, char *procedure, unsigned char *realm32,
                    unsigned char *realm_ca_pem, int realm_ca_pem_len, char *expected_org,
                    uintptr_t identity_handle, unsigned char *station_out, uint16_t *port_out, char **err_out);
                uintptr_t macula_call_direct(uintptr_t resolve_via_session_handle, char *procedure, unsigned char *realm32,
                    int payload_kind, long long payload_int, unsigned char *payload_bytes, int payload_bytes_len, double payload_float,
                    int timeout_ms, uintptr_t identity_handle, char **err_out);
                uintptr_t macula_call_direct_with_cert_chain(uintptr_t resolve_via_session_handle, char *procedure, unsigned char *realm32,
                    unsigned char *realm_ca_pem, int realm_ca_pem_len, char *expected_org,
                    int payload_kind, long long payload_int, unsigned char *payload_bytes, int payload_bytes_len, double payload_float,
                    int timeout_ms, uintptr_t identity_handle, char **err_out);
                uintptr_t macula_call_direct_with_ucan(uintptr_t resolve_via_session_handle, char *procedure, unsigned char *realm32,
                    int payload_kind, long long payload_int, unsigned char *payload_bytes, int payload_bytes_len, double payload_float,
                    int timeout_ms, uintptr_t identity_handle, unsigned char *ucan_token, int ucan_token_len, char **err_out);
                void macula_advertise_direct(uintptr_t session_handle, char *procedure, unsigned char *realm32,
                    long long ttl_ms, uintptr_t identity_handle, char **err_out);
                void macula_advertise_direct_with_cert_chain(uintptr_t session_handle, char *procedure, unsigned char *realm32,
                    long long ttl_ms, unsigned char *cert_chain_pem, int cert_chain_pem_len, uintptr_t identity_handle, char **err_out);
                uintptr_t macula_stream_open_direct(uintptr_t resolve_via_session_handle, char *procedure, unsigned char *realm32, int mode,
                    int args_kind, long long args_int, unsigned char *args_bytes, int args_bytes_len, double args_float,
                    long long deadline_ms, int timeout_ms, uintptr_t identity_handle, uintptr_t *session_handle_out, char **err_out);
                uintptr_t macula_stream_open_direct_with_cert_chain(uintptr_t resolve_via_session_handle, char *procedure, unsigned char *realm32,
                    unsigned char *realm_ca_pem, int realm_ca_pem_len, char *expected_org, int mode,
                    int args_kind, long long args_int, unsigned char *args_bytes, int args_bytes_len, double args_float,
                    long long deadline_ms, int timeout_ms, uintptr_t identity_handle, uintptr_t *session_handle_out, char **err_out);
                int macula_put_direct(uintptr_t resolve_via_session_handle, unsigned char *station32,
                    unsigned char *data, int data_len, char *name, int timeout_ms,
                    uintptr_t identity_handle, unsigned char *mcid_out, char **err_out);
                uintptr_t macula_get_direct(uintptr_t resolve_via_session_handle, unsigned char *mcid34, int timeout_ms,
                    uintptr_t identity_handle, char **err_out);

                uintptr_t macula_ucan_create(char *issuer, char *audience, char *capabilities_json,
                    int has_expires_at, long long expires_at_unix_sec, int has_not_before, long long not_before_unix_sec,
                    uintptr_t identity_handle, char **err_out);
                uintptr_t macula_ucan_verify(unsigned char *token_bytes, int token_len, unsigned char *public_key32, char **err_out);
                uintptr_t macula_ucan_decode(unsigned char *token_bytes, int token_len, char **err_out);
                int macula_ucan_is_expired(unsigned char *token_bytes, int token_len, char **err_out);
                char *macula_ucan_payload_issuer(uintptr_t payload_handle);
                char *macula_ucan_payload_audience(uintptr_t payload_handle);
                long long macula_ucan_payload_expires_at(uintptr_t payload_handle, int *has_out);
                long long macula_ucan_payload_not_before(uintptr_t payload_handle, int *has_out);
                char *macula_ucan_payload_capabilities_json(uintptr_t payload_handle);
                char *macula_ucan_payload_proofs_json(uintptr_t payload_handle);
                void macula_ucan_payload_free(uintptr_t payload_handle);
                uintptr_t macula_call_with_ucan(uintptr_t session_handle, char *procedure, unsigned char *realm32,
                    int payload_kind, long long payload_int, unsigned char *payload_bytes, int payload_bytes_len, double payload_float,
                    int timeout_ms, uintptr_t identity_handle, unsigned char *ucan_token, int ucan_token_len, char **err_out);
                uintptr_t macula_serve_wait_for_call_gated(uintptr_t session_handle, uintptr_t identity_handle, int timeout_ms,
                    unsigned char *required_issuer32, char **err_out);
                CDEF, $libPath);
        }

        return self::$ffi;
    }

    /**
     * Runs $fn with a `char **err_out` slot, throws RuntimeException with
     * the Go-side error text if $fn leaves it set, frees the C string
     * either way.
     *
     * @template T
     * @param callable(\FFI\CData): T $fn
     * @return T
     */
    public static function withErrOut(callable $fn): mixed
    {
        $ffi = self::get();
        $errOut = $ffi->new('char*'); // zero-initialized (NULL) by FFI::new
        $result = $fn(\FFI::addr($errOut));
        if (!\FFI::isNull($errOut)) {
            $message = \FFI::string($errOut);
            $ffi->macula_free_string($errOut);
            throw new \RuntimeException($message);
        }
        return $result;
    }

    /** Reads a 32-byte FFI buffer into a PHP binary string. */
    public static function readBytes32(\FFI\CData $buf): string
    {
        return \FFI::string($buf, 32);
    }

    /**
     * Allocates an `unsigned char*`-compatible buffer holding $s's raw
     * bytes. Every function taking a payload/args/body Value needs its
     * bytes as an actual C buffer, not a PHP string -- passing a PHP
     * string directly where `unsigned char*` (not `char*`) is expected
     * doesn't reliably auto-convert the way it does for `char*`
     * parameters like `procedure`/`topic`/`host`.
     */
    public static function cBytes(string $s): \FFI\CData
    {
        $len = strlen($s);
        $buf = self::get()->new(\FFI::arrayType(self::get()->type('unsigned char'), [max($len, 1)]));
        if ($len > 0) {
            \FFI::memcpy($buf, $s, $len);
        }
        return $buf;
    }

    /**
     * Reads a variable-length byte buffer via the *_len-then-read
     * accessor pair every response/event/item/reply payload uses
     * (e.g. macula_response_result_bytes_len + macula_response_result_bytes).
     *
     * @param callable(): int $lenFn
     * @param callable(\FFI\CData): void $readFn
     */
    public static function readVarBytes(callable $lenFn, callable $readFn): string
    {
        $len = $lenFn();
        if ($len <= 0) {
            return '';
        }
        $buf = self::get()->new(\FFI::arrayType(self::get()->type('unsigned char'), [$len]));
        $readFn($buf);
        return \FFI::string($buf, $len);
    }

    /** Builds a Value from the four fields an accessor quartet/quintet exposes. */
    public static function valueFromParts(int $kind, int $intVal, string $bytesVal, float $floatVal): Value
    {
        return match ($kind) {
            Value::KIND_INT => Value::int($intVal),
            Value::KIND_BYTES => Value::bytes($bytesVal),
            Value::KIND_TEXT => Value::text($bytesVal),
            Value::KIND_FLOAT => Value::float($floatVal),
            default => Value::null(),
        };
    }
}
