<?php

declare(strict_types=1);

// Opens the server stream 04_stream_serve.php serves and prints its chunks.
// Its own key is caller.key, so it is a different node from the server.
// Run: MACULA_PROCEDURE=<what the server printed> php examples/04_stream_open.php

require __DIR__ . '/mesh.php';

use Macula\StreamData;
use Macula\StreamEnd;
use Macula\StreamMode;

$pool = connect('caller.key');
$stream = $pool->openStream(realm(), env('MACULA_PROCEDURE'), StreamMode::Server);
foreach ($stream as $event) {
    if ($event instanceof StreamData) {
        echo 'chunk ', hex2bin(substr($event->body, 2)), "\n";
    }
    if ($event instanceof StreamEnd) {
        break;
    }
}
$stream->free();
$pool->close();
