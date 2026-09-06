<?php

declare(strict_types=1);

/**
 * Quickstart, provider half: advertises a trivial echo procedure and
 * serves exactly one inbound CALL to it (echoing the payload back
 * unchanged), then exits. Not meant to be run alone -- see
 * 00_run_quickstart.sh, which runs this alongside 00_quickstart_call.php
 * as two real OS processes (not pcntl_fork() -- see the README's
 * "Two-process pattern" section for why).
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;

[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
$session->advertise($procedure, $realm);

$pending = $session->serveWaitForCall(15000);
fprintf(STDERR, "[provider] serving CALL for procedure=%s\n", $pending->procedure());

$pending->replyResult($pending->payload());

$session->close();
