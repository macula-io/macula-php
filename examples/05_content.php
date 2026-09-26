<?php

declare(strict_types=1);

// Shares content from one node and fetches it from another, checked against
// its content id: no realm key is needed to fetch. The sharing node serves it
// on its own ~<node_id>/content_v1 until it unshares it or closes.
// Run: php examples/05_content.php

require __DIR__ . '/mesh.php';

$sharer = connect();
$fetcher = connect('caller.key');
$data = str_repeat('macula ', 100_000);
$mcid = $sharer->shareContent(realm(), $data, 'demo.txt');
echo "shared ", strlen($data), " bytes as {$mcid}\n";
$got = $fetcher->getContent(realm(), $mcid);
echo 'fetched ', strlen($got), ' bytes, ', $got === $data ? 'identical' : 'DIFFERENT', "\n";
$sharer->unshareContent(realm(), $mcid);
$sharer->close();
$fetcher->close();
