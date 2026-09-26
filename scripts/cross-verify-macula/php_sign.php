<?php

declare(strict_types=1);

// This SDK's half of scripts/cross-verify-macula.sh: a pq_hybrid key made for
// the run and never saved signs a message; the message, the public key as
// carried and the signature go to argv[1] for macula to verify.

require __DIR__ . '/../../vendor/autoload.php';

use Macula\NodeKey;
use Macula\Profile;

$dir = $argv[1];
$key = NodeKey::generate(Profile::PqHybrid);
$message = 'signed by macula-php';
$signature = $key->sign($message);
if (!NodeKey::verify($message, $signature, $key->publicKey(), Profile::PqHybrid)) {
    fwrite(STDERR, "macula-php does not verify its own composite\n");
    exit(1);
}
file_put_contents("{$dir}/m.bin", $message);
file_put_contents("{$dir}/pk.bin", $key->publicKey());
file_put_contents("{$dir}/s.bin", $signature);
echo 'php_signed: ', strlen($signature), "-byte composite by macula-php written\n";
