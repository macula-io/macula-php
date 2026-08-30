<?php

declare(strict_types=1);

/**
 * UCAN-gated serving, provider half: advertises a procedure and serves
 * it via serveWaitForCallGated(), requiring a token from
 * $requiredIssuerHex (a 32-byte Ed25519 public key, passed by the
 * orchestrator so the caller can mint a matching token).
 *
 * The caller makes two attempts: one without a token first (refused with
 * BOLT#4 Unauthorized at the station-facing dispatch itself -- this
 * script's own handler logic never runs for it at all, which is the
 * point of gating: serveWaitForCallGated() throws for this case rather
 * than returning a PendingCall, since there is nothing for this script
 * to inspect or reply to) and one with a valid token (reaches
 * replyResult() below). This loop catches and logs the expected
 * rejection, then serves the real one. Not meant to be run alone -- see
 * 09_run_ucan_gated_serve.sh.
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
$session->advertise($procedure, $realm);
fprintf(STDERR, "[provider] advertised %s, requiring issuer=%s\n", $procedure, $requiredIssuerHex);

$answered = 0;
while ($answered < 1) {
    try {
        $pending = $session->serveWaitForCallGated($requiredIssuer, 15000);
    } catch (\RuntimeException $e) {
        fprintf(STDERR, "[provider] a CALL was refused by policy before reaching this script (expected for the unauthorized attempt): %s\n", $e->getMessage());
        continue;
    }
    fprintf(STDERR, "[provider] serving authorized GATED CALL for procedure=%s\n", $pending->procedure());
    $pending->replyResult(Value::int($pending->payload()->intValue * 2));
    $answered++;
}

$session->close();
