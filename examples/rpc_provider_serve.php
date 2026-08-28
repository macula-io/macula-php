<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

// Provider half -- two OS processes, not pcntl_fork(), same reasoning
// as examples/stream_provider_serve.php.
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
