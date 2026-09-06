#!/usr/bin/env bash
set -euo pipefail

# Quickstart: two independent PHP processes -- one advertises and serves
# a trivial echo procedure (Session::serveWaitForCall / PendingCall, see
# 00_quickstart_serve.php), the other calls it (see 00_quickstart_call.php).
# Two real OS processes, not pcntl_fork(): fork() after loading a
# cgo-backed shared library is unsafe for continued execution in the
# child -- see the README's "Two-process pattern" section.
#
# Run: bash examples/00_run_quickstart.sh

cd "$(dirname "$0")/.."

# Unique per run -- reusing a fixed procedure name across rapid repeated
# runs can hit stale DHT routing state from the prior run's now-dead
# advertiser.
procedure="macula_php.quickstart_echo.$(openssl rand -hex 8)"
realm_hex="$(openssl rand -hex 32)"

php examples/00_quickstart_serve.php "$procedure" "$realm_hex" &
provider_pid=$!

sleep 0.5 # ADVERTISE is fire-and-forget; give it a moment to land

php examples/00_quickstart_call.php "$procedure" "$realm_hex"

wait "$provider_pid"
