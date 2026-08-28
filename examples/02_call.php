<?php

declare(strict_types=1);

/**
 * Unary RPC, caller role: send a signed CALL and wait for the matching
 * RESULT or ERROR. Calls a procedure name that certainly doesn't exist
 * -- the point isn't to exercise any particular procedure, only to
 * prove the round trip: a real ERROR (BOLT#4 `unknown_next_peer`) comes
 * back, correctly correlated to this call.
 *
 * Run: php examples/02_call.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$realm = str_repeat("\x00", 32); // the "no realm" sentinel is fine for a probe like this
$response = $session->call('macula_php_sdk.test_probe', $realm, Value::text('hello'), timeoutMs: 10000);

if ($response->isError()) {
    printf(
        "OBSERVED: got an ERROR (expected for a nonexistent procedure): code=%d name=%s reported_by=%s detail=%s\n",
        $response->code(),
        $response->name(),
        bin2hex($response->reportedBy()),
        $response->detail() ?? '(none)',
    );
} else {
    printf(
        "OBSERVED: got a RESULT (unexpected for a made-up procedure, but valid): payload=%s responded_by=%s\n",
        $response->payload()->asText(),
        bin2hex($response->respondedBy()),
    );
}

$session->close();
echo "OK\n";
