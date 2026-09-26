<?php

declare(strict_types=1);

// Serves a procedure until interrupted: MACULA_PROCEDURE, or this node's own
// ~<node_id>/echo when unset, which needs no org and no realm key. An org
// procedure (acme/echo) needs the realm to have admitted the org and the org
// to have delegated its procedures to this node.
// Run: php examples/02_serve.php, then call it from another process.

require __DIR__ . '/mesh.php';

use Macula\Request;

$pool = connect();
$procedure = getenv('MACULA_PROCEDURE') ?: $pool->ownProcedure('echo');
$served = $pool->serve(realm(), $procedure);
echo "serving {$procedure} as {$pool->nodeId()}; Ctrl-C to stop\n";

// A worker loop: each call waits in the served procedure until handle()
// takes it, and is answered with what the handler returns, or a
// handler_error with the message of what it throws.
while (true) {
    $served->handle(function (Request $request): mixed {
        echo "call from {$request->caller}\n";
        return $request->payload;
    }, 1_000);
}
