<?php

declare(strict_types=1);

/**
 * UCAN-gated serving, caller half: first calls the procedure
 * 09_ucan_gated_serve.php is serving WITHOUT a token (expects
 * BOLT#4 Unauthorized -- refused before the provider's own handler logic
 * ever runs), then mints a real token from $authoritySeedHex (the
 * required issuer's seed, shared with the provider only as its derived
 * public key) and calls again WITH it (expects the doubled RESULT).
 * Not meant to be run alone -- see 09_run_ucan_gated_serve.sh.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Ucan;
use Macula\Value;

[$procedure, $realmHex, $authoritySeedHex] = [$argv[1], $argv[2], $argv[3]];
$realm = hex2bin($realmHex);
$authority = KeyPair::fromSeedBytes(hex2bin($authoritySeedHex));

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$unauthorized = $session->call($procedure, $realm, Value::int(21), 10000);
if (!$unauthorized->isError() || $unauthorized->code() !== 0x10) {
    fwrite(STDERR, "[caller] expected BOLT#4 Unauthorized (0x10) without a token, got isError=" . ($unauthorized->isError() ? '1' : '0') . " code={$unauthorized->code()} name={$unauthorized->name()}\n");
    exit(1);
}
echo "[caller] call without a token correctly refused as Unauthorized\n";

$token = Ucan::create(
    issuer: 'did:macula:example-authority',
    audience: 'did:macula:example-caller',
    capabilities: [],
    identity: $authority,
    expiresAtUnixSec: time() + 60,
);

$authorized = $session->callWithUcan($procedure, $realm, Value::int(21), $token, 10000);
if ($authorized->isError()) {
    fwrite(STDERR, "[caller] expected a RESULT with a valid token, got ERROR code={$authorized->code()} name={$authorized->name()}\n");
    exit(1);
}
$result = $authorized->payload()->intValue;
if ($result !== 42) {
    fwrite(STDERR, "[caller] expected RESULT 42, got {$result}\n");
    exit(1);
}
echo "[caller] call with a valid token got real RESULT 42\n";

$session->close();
echo "OK\n";
