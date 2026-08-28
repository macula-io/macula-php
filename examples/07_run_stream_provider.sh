#!/usr/bin/env bash
set -euo pipefail

# Streaming RPC, provider role: two independent PHP processes to the
# SAME live station -- one advertises a procedure and accepts an
# inbound stream for it (see 07_stream_provider_serve.php), the other
# dials in and pulls data (see 07_stream_provider_call.php). Two real
# OS processes, not pcntl_fork(): fork() after loading a cgo-backed
# shared library is unsafe for continued execution in the child -- see
# the README's "Two-process pattern" section.
#
# Run: bash examples/07_run_stream_provider.sh

cd "$(dirname "$0")/.."

procedure="macula_php_sdk.test_provider.$(openssl rand -hex 8)"
realm_hex="$(openssl rand -hex 32)"

php examples/07_stream_provider_serve.php "$procedure" "$realm_hex" &
provider_pid=$!

sleep 0.5 # give the station a moment to register the advertisement

php examples/07_stream_provider_call.php "$procedure" "$realm_hex"

wait "$provider_pid"
