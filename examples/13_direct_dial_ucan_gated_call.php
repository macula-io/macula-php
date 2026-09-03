<?php

declare(strict_types=1);

/**
 * Direct-dial + UCAN gating together, caller half: resolves the procedure
 * via the mesh DHT (resolveDirect, proving it's actually direct-dial-only
 * -- a plain call() could never even find it), then makes three calls
 * against the direct-dialed station: no token (expect Unauthorized),
 * a token from the WRONG issuer (expect Unauthorized -- callDirectWithUcan
 * exists and attaches it, but the issuer doesn't match), and a valid
 * token from the shared authority seed (expect the doubled RESULT). Not
 * meant to be run alone -- see 13_run_direct_dial_ucan_gated.sh.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Ucan;
use Macula\Value;

[$procedure, $realmHex, $authoritySeedHex] = [$argv[1], $argv[2], $argv[3]];
$realm = hex2bin($realmHex);
$authority = KeyPair::fromSeedBytes(hex2bin($authoritySeedHex));
$otherAuthority = KeyPair::generate();

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$resolved = $session->resolveDirect($procedure, $realm);
fprintf(STDERR, "[caller] resolved %s -> station=%s host=%s port=%d\n", $procedure, bin2hex($resolved['station']), $resolved['host'], $resolved['port']);

// A fresh resolveVia session per attempt -- found live while building this
// example: reusing ONE session as resolveVia for repeated direct-dial
// resolves against the same advertisement reliably fails the 2nd/3rd
// resolve with ErrProcedureNotAdvertised, even though the record is still
// live (the FIRST resolve on that session, and every resolve on a fresh
// session, succeed). Not a defect in callDirect()/callDirectWithUcan()
// themselves -- the equivalent Go-level live test
// (directdial_live_test.go's TestLiveDirectDialUCANGatedRoundTrip) reuses
// one resolveVia session across all 3 calls with no failure, so this is
// specific to this PHP/cabi layer somewhere between here and Go's
// dht.FindRecords, not the underlying wire protocol. Flagged, not chased
// further here -- out of scope for the UCAN-over-direct-dial fix this
// example exists to prove; a fresh session per resolve is a reasonable
// caller pattern regardless.
$session2 = Session::connect('station-de-frankfurt.macula.io', 4433, KeyPair::generate());
$session3 = Session::connect('station-de-frankfurt.macula.io', 4433, KeyPair::generate());

$noToken = $session->callDirect($procedure, $realm, Value::int(21), 10000);
if (!$noToken->isError() || $noToken->code() !== 0x10) {
    fwrite(STDERR, "[caller] no-token callDirect: expected BOLT#4 Unauthorized (0x10), got isError=" . ($noToken->isError() ? '1' : '0') . " code={$noToken->code()} name={$noToken->name()}\n");
    exit(1);
}
echo "[caller] callDirect() without a token correctly refused as Unauthorized\n";

$wrongToken = Ucan::create(
    issuer: 'did:macula:example-wrong-authority',
    audience: 'did:macula:example-caller',
    capabilities: [],
    identity: $otherAuthority,
    expiresAtUnixSec: time() + 60,
);
$wrongIssuer = $session2->callDirectWithUcan($procedure, $realm, Value::int(21), $wrongToken, 10000);
if (!$wrongIssuer->isError() || $wrongIssuer->code() !== 0x10) {
    fwrite(STDERR, "[caller] wrong-issuer callDirectWithUcan: expected BOLT#4 Unauthorized (0x10), got isError=" . ($wrongIssuer->isError() ? '1' : '0') . " code={$wrongIssuer->code()} name={$wrongIssuer->name()}\n");
    exit(1);
}
echo "[caller] callDirectWithUcan() with a wrong-issuer token correctly refused as Unauthorized\n";

$validToken = Ucan::create(
    issuer: 'did:macula:example-authority',
    audience: 'did:macula:example-caller',
    capabilities: [],
    identity: $authority,
    expiresAtUnixSec: time() + 60,
);
$authorized = $session3->callDirectWithUcan($procedure, $realm, Value::int(21), $validToken, 10000);
if ($authorized->isError()) {
    fwrite(STDERR, "[caller] valid-token callDirectWithUcan: expected a RESULT, got ERROR code={$authorized->code()} name={$authorized->name()}\n");
    exit(1);
}
$result = $authorized->payload()->intValue;
if ($result !== 42) {
    fwrite(STDERR, "[caller] expected RESULT 42, got {$result}\n");
    exit(1);
}
echo "[caller] callDirectWithUcan() with a valid token got real RESULT 42 -- a UCAN-gated capability reached purely through direct-dial\n";

$session->close();
echo "OK\n";
