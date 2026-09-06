<?php

declare(strict_types=1);

/**
 * Quickstart, caller half: calls the procedure 00_quickstart_serve.php
 * is serving in a separate process and prints the echoed response back.
 * Not meant to be run alone -- see 00_run_quickstart.sh.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$response = $session->call($procedure, $realm, Value::text('hello'), 10000);
if ($response->isError()) {
    fwrite(STDERR, "[caller] expected a RESULT, got ERROR code={$response->code()} name={$response->name()}\n");
    exit(1);
}
printf("call response: %s\n", $response->payload()->asText());

$session->close();
echo "OK\n";
