<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;

$identity = KeyPair::generate();
printf("identity node_id: %s\n", bin2hex($identity->nodeId()));

$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
printf("accepted: %s\n", $session->accepted ? 'true' : 'false');
printf("station node_id: %s\n", bin2hex($session->stationNodeId));

$session->close();

if (!$session->accepted) {
    fwrite(STDERR, "station did not accept the connection\n");
    exit(1);
}
echo "OK\n";
