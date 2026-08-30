#!/usr/bin/env bash
set -euo pipefail

# UCAN-gated serving: two independent PHP processes -- one advertises and
# serves a procedure requiring a token from a specific issuer
# (Session::serveWaitForCallGated, see 09_ucan_gated_serve.php), the
# other calls it once without a token (expects Unauthorized) and once
# with a valid one minted from the shared authority seed
# (Session::callWithUcan, see 09_ucan_gated_serve_call.php).
#
# Run: bash examples/09_run_ucan_gated_serve.sh

cd "$(dirname "$0")/.."

procedure="macula_php_sdk.test_ucan_gated_double.$(openssl rand -hex 8)"
realm_hex="$(openssl rand -hex 32)"
authority_seed_hex="$(openssl rand -hex 32)"
required_issuer_hex="$(php -r '
require __DIR__ . "/vendor/autoload.php";
echo bin2hex(\Macula\KeyPair::fromSeedBytes(hex2bin($argv[1]))->nodeId());
' "$authority_seed_hex")"

php examples/09_ucan_gated_serve.php "$procedure" "$realm_hex" "$required_issuer_hex" &
provider_pid=$!

sleep 0.5 # give the station a moment to register the advertisement

php examples/09_ucan_gated_serve_call.php "$procedure" "$realm_hex" "$authority_seed_hex"

wait "$provider_pid"
