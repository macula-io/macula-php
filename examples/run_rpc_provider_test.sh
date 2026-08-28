#!/usr/bin/env bash
set -euo pipefail

# Two independent PHP processes: one advertises and serves a unary
# RPC procedure (Session::serveWaitForCall/PendingCall), the other
# calls it -- same two-OS-process reasoning as
# run_stream_provider_test.sh.

cd "$(dirname "$0")/.."

procedure="macula_php_sdk.test_double.$(openssl rand -hex 8)"
realm_hex="$(openssl rand -hex 32)"

php examples/rpc_provider_serve.php "$procedure" "$realm_hex" &
provider_pid=$!

sleep 0.5

php examples/rpc_provider_call.php "$procedure" "$realm_hex"

wait "$provider_pid"
