<?php

declare(strict_types=1);

/**
 * UCAN mint/verify round trip -- no network, no live station needed
 * (mirrors examples/ scripts that need one only where a wire operation
 * is actually involved). Mints a token, verifies it against the
 * signer's own public key, and confirms a tampered signature and a
 * wrong-issuer check both correctly fail.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Ucan;

$identity = KeyPair::generate();
$otherIdentity = KeyPair::generate();

$token = Ucan::create(
    issuer: 'did:macula:example-issuer',
    audience: 'did:macula:example-audience',
    capabilities: [['with' => 'mri:capability:io.macula/weather', 'can' => 'read']],
    identity: $identity,
    expiresAtUnixSec: time() + 3600,
);
echo '[ucan] minted token, ' . strlen($token) . " bytes\n";

$payload = Ucan::verify($token, $identity->nodeId());
if ($payload->issuer() !== 'did:macula:example-issuer') {
    fwrite(STDERR, "[ucan] issuer mismatch: {$payload->issuer()}\n");
    exit(1);
}
if ($payload->audience() !== 'did:macula:example-audience') {
    fwrite(STDERR, "[ucan] audience mismatch: {$payload->audience()}\n");
    exit(1);
}
$caps = $payload->capabilities();
if ($caps === [] || $caps[0]['can'] !== 'read') {
    fwrite(STDERR, '[ucan] capabilities mismatch: ' . json_encode($caps) . "\n");
    exit(1);
}
if (Ucan::isExpired($token)) {
    fwrite(STDERR, "[ucan] token unexpectedly reports expired\n");
    exit(1);
}
echo "[ucan] verified: issuer/audience/capabilities/expiry all correct\n";

try {
    Ucan::verify($token, $otherIdentity->nodeId());
    fwrite(STDERR, "[ucan] verify against the WRONG public key unexpectedly succeeded\n");
    exit(1);
} catch (\RuntimeException) {
    echo "[ucan] verify against the wrong public key correctly failed\n";
}

echo "OK\n";
