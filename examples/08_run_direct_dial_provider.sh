#!/usr/bin/env bash
set -euo pipefail

# Direct-dial RPC, provider role: two independent PHP processes with two
# DISTINCT identities (this fleet kicks whichever connection reuses one
# second) -- one publishes a direct-dial advertisement and serves a
# procedure (Session::advertiseDirect/serveWaitForCall, see
# 08_direct_dial_provider_serve.php), the other resolves it via the mesh
# DHT and dials the serving station directly
# (Session::resolveDirect/callDirect, see 08_direct_dial_provider_call.php).
#
# Run: bash examples/08_run_direct_dial_provider.sh

cd "$(dirname "$0")/.."

procedure="macula_php_sdk.test_direct_double.$(openssl rand -hex 8)"
realm_hex="$(openssl rand -hex 32)"

php examples/08_direct_dial_provider_serve.php "$procedure" "$realm_hex" &
provider_pid=$!

sleep 0.5 # give the station a moment to register the advertisement

php examples/08_direct_dial_provider_call.php "$procedure" "$realm_hex"

wait "$provider_pid"
