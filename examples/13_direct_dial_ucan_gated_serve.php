<?php

declare(strict_types=1);

/**
 * Direct-dial + UCAN gating together, provider half: publishes a signed
 * procedure_advertisement DHT record via advertiseDirect() (in ADDITION
 * to the ordinary ADVERTISE registration), then serves inbound calls
 * gated to $requiredIssuerHex via serveWaitForCallGated() -- proving
 * these two features actually compose. They shipped independently
 * (08_*.php / 09_*.php) and were never verified together until
 * callDirectWithUcan() existed on the caller side: every hecate-om
 * capability is advertised via advertise_direct, so a `ucan_required`
 * capability was reachable in name only before this. Not meant to be run
 * alone -- see 13_run_direct_dial_ucan_gated.sh.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

[$procedure, $realmHex, $requiredIssuerHex] = [$argv[1], $argv[2], $argv[3]];
$realm = hex2bin($realmHex);
$requiredIssuer = hex2bin($requiredIssuerHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
$session->advertiseDirect($procedure, $realm, 60000);
fprintf(STDERR, "[provider] advertised %s directly (plain + DHT record), requiring issuer=%s\n", $procedure, $requiredIssuerHex);

$answered = 0;
while ($answered < 1) {
    try {
        $pending = $session->serveWaitForCallGated($requiredIssuer, 15000);
    } catch (\RuntimeException $e) {
        fprintf(STDERR, "[provider] a CALL was refused by policy before reaching this script (expected for the unauthorized/wrong-issuer attempts): %s\n", $e->getMessage());
        continue;
    }
    fprintf(STDERR, "[provider] serving authorized GATED CALL for procedure=%s\n", $pending->procedure());
    $pending->replyResult(Value::int($pending->payload()->intValue * 2));
    $answered++;
}

$session->close();
