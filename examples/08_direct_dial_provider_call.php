<?php

declare(strict_types=1);

/**
 * Direct-dial RPC, caller half: resolves the procedure
 * 08_direct_dial_provider_serve.php advertised via the mesh DHT, dials
 * its serving station directly (in one hop, not depending on ordinary
 * advertise-gossip having propagated a route), and checks the doubled
 * result comes back correctly -- a real RESULT payload, not merely
 * "reached the call stage" (that weaker bar already hid a real bug in
 * macula-go's own AdvertiseDirect earlier in this SDK family's
 * development). Not meant to be run alone -- see
 * 08_run_direct_dial_provider.sh.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$resolved = $session->resolveDirect($procedure, $realm);
fprintf(STDERR, "[caller] resolved %s -> station=%s host=%s port=%d\n", $procedure, bin2hex($resolved['station']), $resolved['host'], $resolved['port']);

$response = $session->callDirect($procedure, $realm, Value::int(21), 10000);
if ($response->isError()) {
    fwrite(STDERR, "[caller] expected a RESULT, got ERROR code={$response->code()} name={$response->name()}\n");
    exit(1);
}
$result = $response->payload()->intValue;
if ($result !== 42) {
    fwrite(STDERR, "[caller] expected RESULT 42, got {$result}\n");
    exit(1);
}
echo "[caller] got real RESULT 42 through direct-dial\n";

$session->close();
echo "OK\n";
