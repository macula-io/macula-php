<?php

declare(strict_types=1);

/**
 * Streaming RPC, caller role only: open a dedicated stream against a
 * deliberately nonexistent procedure, push one chunk, half-close, and
 * see what the station does with an unknown procedure. Proves the wire
 * mechanics (opening a dedicated stream, STREAM_OPEN/DATA/END, awaiting
 * a reply) rather than a specific procedure's behavior -- for a real
 * two-role round trip (this session's stream actually being served by
 * another), see 07_stream_provider_serve.php / 07_run_stream_provider.sh.
 *
 * Run: php examples/05_stream_open_caller.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\StreamEncoding;
use Macula\StreamMode;
use Macula\Value;

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
printf("connected: accepted=%s\n", $session->accepted ? 'true' : 'false');

$realm = str_repeat("\x00", 32);
$deadlineMs = (int) (microtime(true) * 1000) + 10000;
$handle = $session->streamOpen('macula_php_sdk.test_stream', $realm, StreamMode::CLIENT_STREAM, Value::null(), $deadlineMs);
echo "opened dedicated stream, sent STREAM_OPEN\n";

$handle->sendData(StreamEncoding::RAW, Value::bytes('hello from macula-php'));
$handle->closeSend();

try {
    $reply = $handle->awaitReply(5000);
    printf("STREAM_REPLY (unexpected but valid): payload=%s responded_by=%s\n", $reply->payload()->asText(), bin2hex($reply->respondedBy()));
} catch (\RuntimeException $e) {
    printf("no reply within 5s, as: %s\n", $e->getMessage());
}

$session->close();
echo "OK\n";
