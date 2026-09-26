<?php

declare(strict_types=1);

// Subscribes to a topic, publishes one message, and prints it as heard.
// Topics name a kind of fact; ids go in the payload.
// Run: php examples/03_publish_subscribe.php

require __DIR__ . '/mesh.php';

$topic = 'acme/demo/greeting_sent_v1';
$pool = connect();
$sub = $pool->subscribe(realm(), $topic);
usleep(300_000);
$pool->publish(realm(), $topic, ['text' => 'hello']);
foreach ($sub->events(2_000) as $event) {
    echo 'heard ', json_encode($event->payload), " from {$event->publisher}\n";
}
$sub->stop();
$pool->close();
