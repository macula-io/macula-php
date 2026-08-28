<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\StreamMode;
use Macula\Value;

// Caller half -- see stream_provider_serve.php for why this is a
// separate process rather than a fork() of the provider's.
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

$item = $handle->recv(5000);
if (!$item->isEof()) {
    fwrite(STDERR, "[caller] expected Eof, got Data\n");
    exit(1);
}
echo "[caller] received Eof\n";

$session->close();
echo "OK\n";
