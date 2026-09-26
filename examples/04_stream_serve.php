<?php

declare(strict_types=1);

// Serves a server stream until interrupted: each session gets three chunks,
// then its end. MACULA_PROCEDURE, or this node's own ~<node_id>/watch.
// Run: php examples/04_stream_serve.php, then 04_stream_open.php with the
// procedure it prints.

require __DIR__ . '/mesh.php';

use Macula\Stream;
use Macula\StreamMode;

$pool = connect();
$procedure = getenv('MACULA_PROCEDURE') ?: $pool->ownProcedure('watch');
$served = $pool->serveStream(realm(), $procedure, StreamMode::Server);
echo "serving {$procedure}; Ctrl-C to stop\n";

// The stream is closed when the handler returns, aborted when it throws,
// and freed either way.
while (true) {
    $served->handle(function (Stream $stream): void {
        foreach (['one', 'two', 'three'] as $chunk) {
            $stream->send($chunk);
        }
    }, 1_000);
}
