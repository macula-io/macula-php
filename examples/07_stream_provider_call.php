<?php

declare(strict_types=1);

/**
 * Streaming RPC, caller half: opens a stream against the procedure
 * 07_stream_provider_serve.php is serving in a separate process, and
 * checks the pushed chunk (then Eof) arrive correctly. Not meant to be
 * run alone -- see 07_run_stream_provider.sh.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\StreamMode;
use Macula\Value;

[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$deadlineMs = (int) (microtime(true) * 1000) + 10000;
$handle = $session->streamOpen($procedure, $realm, StreamMode::SERVER_STREAM, Value::null(), $deadlineMs);
echo "[caller] opened stream\n";

$item = $handle->recv(10000);
if ($item->isEof()) {
    fwrite(STDERR, "[caller] expected Data, got Eof\n");
    exit(1);
}
printf("[caller] received chunk: %s\n", $item->body()->asText());

// closeSend() is fire-and-forget on the wire (no acknowledgment that
// the peer's recv() has seen the STREAM_END yet) -- see the README's
// "Two-process pattern" section. The provider closing its own
// connection shortly after can race the STREAM_END frame it just
// queued, so a raw connection-level EOF here is a legitimate,
// documented alternate outcome to a graceful Eof item, not a failure:
// the data that mattered already arrived above, and either shape
// means the same thing -- nothing more is coming.
try {
    $item = $handle->recv(5000);
    if (!$item->isEof()) {
        fwrite(STDERR, "[caller] expected Eof, got Data\n");
        exit(1);
    }
    echo "[caller] received Eof\n";
} catch (\RuntimeException $e) {
    printf("[caller] connection ended instead of a graceful Eof (%s) -- acceptable, see README\n", $e->getMessage());
}

$session->close();
echo "OK\n";
