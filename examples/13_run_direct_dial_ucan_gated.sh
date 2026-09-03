#!/usr/bin/env bash
set -euo pipefail

# Direct-dial + UCAN gating, together: two independent PHP processes with
# two DISTINCT identities (this fleet kicks whichever connection reuses
# one second) -- one publishes a direct-dial advertisement and serves a
# procedure gated to one issuer (Session::advertiseDirect/serveWaitForCallGated,
# see 13_direct_dial_ucan_gated_serve.php), the other resolves it via the
# mesh DHT, dials the serving station directly, and calls it three ways:
# no token, a wrong-issuer token, and a valid token
# (Session::resolveDirect/callDirect/callDirectWithUcan, see
# 13_direct_dial_ucan_gated_call.php).
#
# This is the actual end-to-end proof for
# PLAN_CLOSE_SERVICE_AUTH_GAPS.md's Phase 0: direct-dial and UCAN gating
# shipped independently (08_*.php / 09_*.php) and were never verified
# working TOGETHER until callDirectWithUcan() existed.
#
# Run: bash examples/13_run_direct_dial_ucan_gated.sh

cd "$(dirname "$0")/.."

procedure="macula_php_sdk.test_direct_ucan_gated_double.$(openssl rand -hex 8)"
realm_hex="$(openssl rand -hex 32)"
authority_seed_hex="$(openssl rand -hex 32)"
required_issuer_hex="$(php -r '
require __DIR__ . "/vendor/autoload.php";
echo bin2hex(\Macula\KeyPair::fromSeedBytes(hex2bin($argv[1]))->nodeId());
' "$authority_seed_hex")"

php examples/13_direct_dial_ucan_gated_serve.php "$procedure" "$realm_hex" "$required_issuer_hex" &
provider_pid=$!

sleep 0.5 # give the station a moment to register the advertisement

php examples/13_direct_dial_ucan_gated_call.php "$procedure" "$realm_hex" "$authority_seed_hex"

wait "$provider_pid"
