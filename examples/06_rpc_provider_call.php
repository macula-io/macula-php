<?php

declare(strict_types=1);

/**
 * Unary RPC, caller half: calls the procedure 06_rpc_provider_serve.php
 * is serving in a separate process, and checks the doubled result comes
 * back correctly. Not meant to be run alone -- see 06_run_rpc_provider.sh.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$response = $session->call($procedure, $realm, Value::int(21), 10000);
if ($response->isError()) {
    fwrite(STDERR, "[caller] expected a RESULT, got ERROR code={$response->code()} name={$response->name()}\n");
    exit(1);
}
$result = $response->payload()->intValue;
if ($result !== 42) {
    fwrite(STDERR, "[caller] expected RESULT 42, got {$result}\n");
    exit(1);
}
echo "[caller] got RESULT 42\n";

$session->close();
echo "OK\n";
