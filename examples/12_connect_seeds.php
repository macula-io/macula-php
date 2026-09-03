<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;

// Session::connectSeeds tries each candidate in order and returns the
// first that answers -- macula-go's connection.ConnectSeeds, the same
// fallback macula-cli's own -seed flag gives every direct-dial
// command. The first seed here is a deliberately unreachable local
// port, so a successful run proves the fallback actually happened,
// not just that the primary worked.
$identity = KeyPair::generate();
printf("identity node_id: %s\n", bin2hex($identity->nodeId()));

$session = Session::connectSeeds(
    ['127.0.0.1:1', 'station-de-frankfurt.macula.io:4433'],
    $identity,
);
printf("accepted: %s\n", $session->accepted ? 'true' : 'false');
printf("station node_id: %s\n", bin2hex($session->stationNodeId));

$session->close();

if (!$session->accepted) {
    fwrite(STDERR, "station did not accept the connection\n");
    exit(1);
}
echo "OK -- fell through the dead first seed to a real station\n";
