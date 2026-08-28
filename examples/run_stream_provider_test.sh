#!/usr/bin/env bash
set -euo pipefail

# Two independent PHP processes to the SAME live station: one advertises
# a procedure and accepts an inbound stream for it (the provider role),
# the other dials in and pulls data (the caller role) -- same pattern
# every sibling SDK in this port series used for its own provider-role
# live test, adapted to PHP's process model.
#
# Two OS processes, not pcntl_fork() within one script: fork() after a
# cgo-backed shared library is loaded is unsafe (it doesn't duplicate
# the extra OS threads Go's scheduler/netpoller depends on) -- see
# stream_provider_serve.php's own doc comment.

cd "$(dirname "$0")/.."

procedure="macula_php_sdk.test_provider.$(openssl rand -hex 8)"
realm_hex="$(openssl rand -hex 32)"

php examples/stream_provider_serve.php "$procedure" "$realm_hex" &
provider_pid=$!

sleep 0.5 # give the station a moment to register the advertisement

php examples/stream_provider_call.php "$procedure" "$realm_hex"

wait "$provider_pid"
