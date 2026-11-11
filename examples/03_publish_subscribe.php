<?php

declare(strict_types=1);

// Subscribes to a topic, publishes one message, and prints it as heard.
// Topics name a kind of fact; ids go in the payload.
// Run: php examples/03_publish_subscribe.php

require __DIR__ . '/mesh.php';

use Macula\Notice;

$topic = 'acme/demo/greeting_sent_v1';
$pool = connect();
$sub = $pool->subscribe(realm(), $topic);
usleep(300_000);
$pool->publish(realm(), $topic, ['text' => 'hello']);
foreach ($sub->events(2_000) as $item) {
    // A notice, not a fact: the link to the station ended, or a gap may have
    // swallowed what was published meanwhile.
    if ($item instanceof Notice) {
        echo "events may be missing ({$item->kind}: {$item->reason})\n";
        continue;
    }
    echo 'heard ', json_encode($item->payload), " from {$item->publisher}\n";
}
$sub->stop();
$pool->close();
