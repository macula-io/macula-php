<?php

declare(strict_types=1);

// Connects to a macula 12 station and calls mcl-echo/echo, which runs on
// another station: the pool finds its trusted advertisement in the DHT and
// dials the station it serves from.
// Run: php examples/01_quickstart.php (with the environment mesh.php reads).

require __DIR__ . '/mesh.php';

$pool = connect();
echo 'node ', $pool->nodeId(), "\n";
foreach ($pool->providers(realm(), 'mcl-echo/echo') as $provider) {
    echo "provider {$provider->node} at station {$provider->station}\n";
}
echo 'mcl-echo/echo answered ', json_encode($pool->call(realm(), 'mcl-echo/echo', 'hello')), "\n";
$pool->close();
