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
 * boilerplate can't be fed to it directly. This is the same eight
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
                void macula_identity_free(uintptr_t identity_handle);
                uintptr_t macula_connect(char *host, uint16_t port, uintptr_t identity_handle, int timeout_ms, char **err_out);
                int macula_session_accepted(uintptr_t session_handle);
                int macula_session_station_node_id(uintptr_t session_handle, unsigned char *out32);
                void macula_session_close(uintptr_t session_handle, uintptr_t identity_handle);
                void macula_free_string(char *s);
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
}
