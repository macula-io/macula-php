<?php

declare(strict_types=1);

namespace Macula;

/**
 * A handshaked connection to a macula-station. Wraps an opaque handle
 * into macula-go-sdk's connection.Session.
 *
 * Holds a reference to the KeyPair it was connected with for its whole
 * lifetime -- macula-go-sdk's own Close needs the identity again to
 * sign the GOODBYE frame, and holding the reference also keeps PHP's
 * refcounting GC from freeing the identity out from under a still-open
 * session (their destruction order isn't otherwise guaranteed).
 */
final class Session
{
    private ?int $handle;

    private function __construct(
        int $handle,
        private readonly KeyPair $identity,
        public readonly bool $accepted,
        public readonly string $stationNodeId,
    ) {
        $this->handle = $handle;
    }

    /**
     * Dial host:port and complete the CONNECT/HELLO handshake, using
     * WebPKI (CA-chain) trust -- the mode the live macula.io fleet
     * actually presents. $timeoutMs bounds the whole operation.
     */
    public static function connect(string $host, int $port, KeyPair $identity, int $timeoutMs = 15000): self
    {
        $ffi = Binding::get();
        $handle = Binding::withErrOut(
            fn (\FFI\CData $errOut) => $ffi->macula_connect(
                $host,
                $port,
                $identity->handleOrFail(),
                $timeoutMs,
                $errOut,
            )
        );

        $accepted = $ffi->macula_session_accepted($handle) !== 0;
        $buf = $ffi->new('unsigned char[32]');
        $ffi->macula_session_station_node_id($handle, $buf);
        $stationNodeId = Binding::readBytes32($buf);

        return new self($handle, $identity, $accepted, $stationNodeId);
    }

    /** Sends a GOODBYE and closes the connection. A no-op if already closed. */
    public function close(): void
    {
        if ($this->handle !== null) {
            Binding::get()->macula_session_close($this->handle, $this->identity->handleOrFail());
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
