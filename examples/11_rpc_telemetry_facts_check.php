<?php

declare(strict_types=1);

/**
 * Confirms RPC telemetry auto-facts (rpc.sent_v1/rpc.completed_v1) fire
 * automatically underneath a plain call() -- this SDK adds no new
 * exported function for them; they're a side effect already baked into
 * macula-go-sdk's Session.Call, which macula_call already invokes
 * unmodified. Two sessions, two distinct identities: a watcher
 * subscribes to both topics under the SAME realm the call will use
 * BEFORE the call happens, then a separate caller session makes one call
 * against a deliberately unadvertised procedure (its own success/failure
 * is irrelevant -- the facts fire either way, per macula_request.erl's
 * own unconditional announce).
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

$realm = random_bytes(32);
$procedure = 'macula_php_sdk.test_telemetry_facts.' . bin2hex(random_bytes(8));

$watcherIdentity = KeyPair::generate();
$watcher = Session::connect('station-de-frankfurt.macula.io', 4433, $watcherIdentity);
$watcher->subscribe('rpc.sent_v1', $realm);
$watcher->subscribe('rpc.completed_v1', $realm);

$callerIdentity = KeyPair::generate();
$caller = Session::connect('station-de-frankfurt.macula.io', 4433, $callerIdentity);
$response = $caller->call($procedure, $realm, Value::int(1), 8000);
fprintf(STDERR, "[telemetry] call itself %s (irrelevant to whether the facts fire)\n", $response->isError() ? "errored as expected (unadvertised)" : "unexpectedly succeeded");

$seenSent = false;
$seenCompleted = false;
$deadline = microtime(true) + 10.0;
while ((!$seenSent || !$seenCompleted) && microtime(true) < $deadline) {
    try {
        $event = $watcher->recvEvent(3000);
    } catch (\RuntimeException $e) {
        continue; // a timeout here just means keep polling until the deadline
    }
    if ($event->topic() === 'rpc.sent_v1') {
        $seenSent = true;
        echo "[telemetry] observed rpc.sent_v1\n";
    } elseif ($event->topic() === 'rpc.completed_v1') {
        $seenCompleted = true;
        echo "[telemetry] observed rpc.completed_v1\n";
    }
}

$watcher->close();
$caller->close();

if (!$seenSent || !$seenCompleted) {
    fwrite(STDERR, "[telemetry] FAILED -- seenSent=" . ($seenSent ? '1' : '0') . " seenCompleted=" . ($seenCompleted ? '1' : '0') . "\n");
    exit(1);
}
echo "OK\n";
