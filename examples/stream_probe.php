<?php

declare(strict_types=1);

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

$handle->sendData(StreamEncoding::RAW, Value::bytes('hello from macula-php-sdk'));
$handle->closeSend();

try {
    $reply = $handle->awaitReply(5000);
    printf("STREAM_REPLY (unexpected but valid): payload=%s responded_by=%s\n", $reply->payload()->asText(), bin2hex($reply->respondedBy()));
} catch (\RuntimeException $e) {
    printf("no reply within 5s, as: %s\n", $e->getMessage());
}

$session->close();
echo "OK\n";
