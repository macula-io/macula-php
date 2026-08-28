<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);
printf("connected: accepted=%s\n", $session->accepted ? 'true' : 'false');

// Single-block put/get.
$data = random_bytes(4096);
$mcid = $session->contentPut($data, 'test-block');
printf("put single block: mcid=%s\n", bin2hex($mcid));
$fetched = $session->contentGet($mcid);
if ($fetched !== $data) {
    fwrite(STDERR, "single-block MISMATCH\n");
    exit(1);
}
echo "single-block round trip OK\n";

// Chunked put/get -- large enough to force manifest.Create's multi-chunk path.
$chunkSize = 262144; // manifest.DefaultChunkSize
$bigData = random_bytes($chunkSize * 2 + 12345);
$bigMcid = $session->contentPut($bigData, 'test-chunked');
printf("put chunked: mcid=%s size=%d\n", bin2hex($bigMcid), strlen($bigData));
$bigFetched = $session->contentGet($bigMcid);
if ($bigFetched !== $bigData) {
    fwrite(STDERR, "chunked MISMATCH\n");
    exit(1);
}
echo "chunked round trip OK\n";

$session->close();
echo "OK\n";
