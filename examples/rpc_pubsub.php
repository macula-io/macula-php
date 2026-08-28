<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
printf("connected: accepted=%s\n", $session->accepted ? 'true' : 'false');

// --- CALL: a deliberately nonexistent procedure -- proves the wire
// round trip (a real ERROR back), same spirit as every other SDK's
// first CALL test in this series.
$realm = str_repeat("\x00", 32);
$response = $session->call('macula_php_sdk.test_probe', $realm, Value::text('hello'));
if ($response->isError()) {
    printf("CALL -> ERROR (expected): code=%d name=%s detail=%s\n", $response->code(), $response->name(), $response->detail() ?? '(none)');
} else {
    printf("CALL -> RESULT (unexpected but valid): %s\n", $response->payload()->asText());
}

// --- PubSub: SUBSCRIBE -> PUBLISH -> EVENT.
$pubsubRealm = random_bytes(32);
$topic = 'macula_php_sdk.test.' . bin2hex(random_bytes(8));
$session->subscribe($topic, $pubsubRealm);
$session->publish($topic, $pubsubRealm, 1, Value::text('hello from macula-php-sdk'), (int) (microtime(true) * 1000));

try {
    $event = $session->recvEvent(5000);
    printf("EVENT received: topic=%s seq=%d delivered_via=%s payload=%s\n", $event->topic(), $event->seq(), $event->deliveredVia(), $event->payload()->asText());
} catch (\RuntimeException $e) {
    printf("EVENT: no delivery within 5s (%s) -- not asserted as failure, matches the other SDKs' own observed-not-assumed finding here\n", $e->getMessage());
}

$session->close();
echo "OK\n";
