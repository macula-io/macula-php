<?php

declare(strict_types=1);

/**
 * Unary RPC, provider half: advertises a procedure and serves exactly
 * one inbound CALL to it (doubling whatever integer it's sent), then
 * exits. Not meant to be run alone -- see 06_run_rpc_provider.sh, which
 * runs this alongside 06_rpc_provider_call.php as two real OS
 * processes (not pcntl_fork() -- see the README's "Two-process
 * pattern" section for why).
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
$session->advertise($procedure, $realm);

$pending = $session->serveWaitForCall(15000);
fprintf(STDERR, "[provider] serving CALL for procedure=%s\n", $pending->procedure());

$payload = $pending->payload();
$n = $payload->intValue;
$pending->replyResult(Value::int($n * 2));

$session->close();
