<?php

declare(strict_types=1);

/**
 * Direct-dial RPC, provider half: publishes a signed procedure_advertisement
 * DHT record naming this session's own station as the server (in ADDITION
 * to the ordinary ADVERTISE registration -- advertiseDirect() does both,
 * matching macula_response:advertise_direct/6,7's actual two-step
 * behavior), then serves exactly one inbound CALL to it, doubling whatever
 * integer it's sent. Not meant to be run alone -- see
 * 08_run_direct_dial_provider.sh, which runs this alongside
 * 08_direct_dial_provider_call.php as two real OS processes with two
 * DISTINCT identities (this fleet kicks whichever connection reuses an
 * identity second).
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
$session->advertiseDirect($procedure, $realm, 60000);
fprintf(STDERR, "[provider] advertised %s directly (plain + DHT record)\n", $procedure);

$pending = $session->serveWaitForCall(15000);
fprintf(STDERR, "[provider] serving CALL for procedure=%s\n", $pending->procedure());

$payload = $pending->payload();
$n = $payload->intValue;
$pending->replyResult(Value::int($n * 2));

$session->close();
