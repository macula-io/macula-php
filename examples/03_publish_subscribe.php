<?php

declare(strict_types=1);

/**
 * PubSub: SUBSCRIBE, then PUBLISH to that same topic/realm, then wait
 * for the EVENT delivery. Whether a subscriber receives its own
 * publish isn't guaranteed by the protocol -- this observes and
 * reports rather than assuming an answer (macula-go's and
 * macula-rust-sdk's own live tests found "yes, delivered_via=direct,
 * essentially instantly" against this same fleet -- but that's a fact
 * about this station, not a protocol guarantee this example should
 * assert as fact).
 *
 * Run: php examples/03_publish_subscribe.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

// A realm+topic scratch value nobody else would collide with.
$realm = random_bytes(32);
$topic = 'macula_php_sdk.test.' . bin2hex(random_bytes(8));

$session->subscribe($topic, $realm);
$session->publish($topic, $realm, seq: 1, payload: Value::text('hello from macula-php'), publishedAtMs: (int) (microtime(true) * 1000));

try {
    $event = $session->recvEvent(timeoutMs: 5000);
    printf(
        "OBSERVED: received our own EVENT back: topic=%s seq=%d delivered_via=%s payload=%s\n",
        $event->topic(),
        $event->seq(),
        $event->deliveredVia(),
        $event->payload()->asText(),
    );
} catch (\RuntimeException $e) {
    printf("OBSERVED: no EVENT arrived within 5s (%s) -- not asserted as a failure either way.\n", $e->getMessage());
}

$session->unsubscribe($topic, $realm);
$session->close();
echo "OK\n";
