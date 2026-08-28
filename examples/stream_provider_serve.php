<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\StreamEncoding;
use Macula\Value;

// Provider half of the two-process streaming test -- see
// stream_provider_call.php and run_stream_provider_test.sh for why
// this is two separate OS processes rather than one script using
// pcntl_fork(): fork() after a cgo-backed shared library (libmacula.so)
// is loaded is unsafe -- it duplicates only the calling thread, not
// the extra OS threads Go's own scheduler/netpoller depends on, so the
// forked child gets a broken copy of the Go runtime. Two real
// processes sidesteps this entirely (each has its own fresh runtime),
// and is also the realistic shape a real deployment takes: a provider
// daemon process, separate from whatever calls it.
[$procedure, $realmHex] = [$argv[1], $argv[2]];
$realm = hex2bin($realmHex);

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
$session->advertise($procedure, $realm);

[$handle, $info] = $session->streamAccept(15000);
fprintf(STDERR, "[provider] accepted stream_open for procedure=%s mode=%d\n", $info->procedure(), $info->mode());
$handle->sendData(StreamEncoding::RAW, Value::bytes('hello from the provider'));
$handle->closeSend();

$session->close();
