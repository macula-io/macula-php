<?php

declare(strict_types=1);

/**
 * Streaming RPC, provider half: advertises a procedure, accepts exactly
 * one inbound stream for it, pushes one chunk, and closes. Not meant to
 * be run alone -- see 07_run_stream_provider.sh, which runs this
 * alongside 07_stream_provider_call.php as two real OS processes (not
 * pcntl_fork() -- see the README's "Two-process pattern" section for
 * why: fork() after loading a cgo-backed shared library is unsafe for
 * continued execution in the child).
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\StreamEncoding;
use Macula\Value;

[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
$session->advertise($procedure, $realm);

[$handle, $info] = $session->streamAccept(15000);
fprintf(STDERR, "[provider] accepted stream_open for procedure=%s mode=%d\n", $info->procedure(), $info->mode());
$handle->sendData(StreamEncoding::RAW, Value::bytes('hello from the provider'));
$handle->closeSend();

// Session::close() tears down the whole QUIC connection immediately
// (macula-go-sdk's own Session.Close calls conn.CloseWithError with no
// drain step) -- closing right after closeSend() can race the
// STREAM_END frame closeSend() just queued, and the caller sees a hard
// connection-level EOF instead of a graceful end-of-stream. In a real
// daemon the connection would simply stay open for the next request;
// here, for a short-lived demo process, give the QUIC stack a moment
// to actually flush before tearing the connection down.
usleep(300_000);
$session->close();
