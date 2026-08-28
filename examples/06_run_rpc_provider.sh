#!/usr/bin/env bash
set -euo pipefail

# Unary RPC, provider role: two independent PHP processes -- one
# advertises and serves a procedure (Session::serveWaitForCall /
# PendingCall, see 06_rpc_provider_serve.php), the other calls it (see
# 06_rpc_provider_call.php). Two real OS processes, not pcntl_fork():
# fork() after loading a cgo-backed shared library is unsafe for
# continued execution in the child -- see the README's "Two-process
# pattern" section.
#
# Run: bash examples/06_run_rpc_provider.sh

cd "$(dirname "$0")/.."

procedure="macula_php_sdk.test_double.$(openssl rand -hex 8)"
realm_hex="$(openssl rand -hex 32)"

php examples/06_rpc_provider_serve.php "$procedure" "$realm_hex" &
provider_pid=$!

sleep 0.5 # give the station a moment to register the advertisement

php examples/06_rpc_provider_call.php "$procedure" "$realm_hex"

wait "$provider_pid"
