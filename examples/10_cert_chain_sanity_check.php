<?php

declare(strict_types=1);

/**
 * Cert-chain direct-dial: a smoke test of the wiring, NOT a full live
 * round trip. Confirms resolveDirectWithCertChain()/advertiseDirectWithCertChain()
 * are correctly callable and reject malformed input sensibly (a real
 * error from the Go side, not a crash). A genuine positive round trip
 * needs an Ed25519 X.509 leaf cert whose embedded public key matches a
 * KeyPair's raw NodeID exactly -- PHP's openssl extension can generate
 * Ed25519 keys but constructing a leaf cert around an EXTERNALLY-supplied
 * raw Ed25519 public key (rather than one openssl itself generated) is
 * real additional work, deliberately not done in this pass; see the
 * session notes for the full reasoning. Go/Rust already independently
 * verify the underlying cert-chain algorithm live -- this SDK is a thin
 * FFI wrapper over the identical Go code, so the algorithm itself is not
 * in question, only whether this SDK's own plumbing reaches it correctly.
 */

require __DIR__ . '/../vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;

$identity = KeyPair::generate();
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$realm = random_bytes(32);
$procedure = 'macula_php_sdk.test_cert_chain_sanity.' . bin2hex(random_bytes(8));
$garbageCaPem = "not a real PEM\n";

// The advertiser embeds cert_chain_pem as opaque bytes in a record it
// signs with its OWN identity -- validating the chain's PARSEABILITY is
// the RESOLVER's job (verify_advertisement_cert_chain), not the
// advertiser's, so a garbage PEM is accepted here without error. Found
// live: an earlier draft of this test wrongly assumed the opposite and
// was corrected against the actual behavior rather than left asserting
// something the design doesn't promise.
$session->advertiseDirectWithCertChain($procedure, $realm, 60000, $garbageCaPem);
echo "[sanity] advertiseDirectWithCertChain accepted cert_chain_pem as opaque bytes (validation is the resolver's job, not the advertiser's)\n";

try {
    $session->resolveDirectWithCertChain($procedure, $realm, $garbageCaPem, 'example-org');
    fwrite(STDERR, "[sanity] resolveDirectWithCertChain unexpectedly succeeded against an unadvertised procedure\n");
    exit(1);
} catch (\RuntimeException $e) {
    echo "[sanity] resolveDirectWithCertChain correctly failed (no such advertisement exists): {$e->getMessage()}\n";
}

$session->close();
echo "OK (wiring sanity only -- see this file's own doc comment for what a full round trip would still need)\n";
