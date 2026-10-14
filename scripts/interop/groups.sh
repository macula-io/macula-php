#!/usr/bin/env bash
# Sealed groups across macula (13.2 on, wire 1.0) and macula-php, both ways, through macula-go's test station and
# macula's own distributor (macula_group_keys): the org's distributor runs in Erlang; an Erlang member and a PHP
# member each subscribe under the group, pulling its epoch from it over a sealed call with the org's grant, and each
# publishes sealed under it; each must open the other's event. Then a PHP node the distributor's removed set names
# must be refused not_a_member, and a PHP node presenting the grant the org minted for another node must be refused.
# The Erlang side runs in macula's pinned CI image.
#
#   MACULA_BUILD=<macula 15.0.0-rc.1 or later, compiled> scripts/interop/groups.sh [pq_hybrid|pq_pure]
#
# MACULA_BUILD may be a rebar3 project that depends on macula from hex, as for cross-verify-macula.sh.
#
# The PHP side is this SDK over the released libmacula in build/native, and the test station is build/teststation:
# run `composer build` first. This SDK holds no epoch key and seals and opens nothing of its own (see
# scripts/interop/phpgroups.php); what this run proves about the PHP side is that the group a PHP app publishes and
# subscribes under is macula's, its events are macula's, and macula's own verdicts reach it unchanged.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
image="${MACULA_CI_IMAGE:-ghcr.io/macula-io/macula-ci-otp@sha256:aff1d39bc4aa29d13044b90b38e9b7f4b757d50818cc11c5bb7e84cdbf82ac70}"
profile="${1:-pq_hybrid}"
: "${MACULA_BUILD:?a compiled macula at 15.0.0-rc.1 or later (wire 1.0)}"
test -x "$root/build/teststation" || { echo "groups.sh: no $root/build/teststation: run \`composer build\`" >&2; exit 1; }
phpgroups="$root/scripts/interop/phpgroups.php"
work="$(mktemp -d)"
shared="$work/shared"
mkdir -p "$shared"
chmod 777 "$shared"
names=("macula-php-groups-dist-$$" "macula-php-groups-member-$$")
cleanup() {
  for n in "${names[@]}"; do podman rm -f "$n" > /dev/null 2>&1 || true; done
  for p in "${php_pids[@]:-}"; do [ -n "$p" ] && kill "$p" 2> /dev/null || true; done
  exec 3>&- || true
  rm -rf "$work"
}
trap cleanup EXIT
php_pids=()

mkfifo "$work/stations.in"
"$root/build/teststation" "$profile" < "$work/stations.in" > "$work/stations.out" &
exec 3> "$work/stations.in"

# nth_line FILE PATTERN N waits up to 180 s for the Nth line of FILE matching PATTERN.
nth_line() {
  for _ in $(seq 1 900); do
    line="$(grep -E "$2" "$1" 2> /dev/null | sed -n "$3p" || true)"
    [ -n "$line" ] && { echo "$line"; return 0; }
    sleep 0.2
  done
  echo "groups.sh: no line $3 matching $2 in $1" >&2
  cat "$1" >&2 || true
  return 1
}
info="$(nth_line "$work/stations.out" '^\{' 1)"
read -r host port station realm realm_key org org_key_id < <(python3 -c '
import json,sys; d=json.loads(sys.argv[1]); s=d["stations"][0]
print(s["host"], s["port"], s["node_id"], d["realm_id"], d["realm_key"], d["org"], d["org_key_id"])' "$info")
realm_name="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["realm_name"])' "$info")"
prefix="$realm_name/$org/chat"
topic="$prefix/said_v1"
grants=0

# grant NODE asks the test station for the org's grant to NODE and writes it where NODE waits for it.
grant() {
  echo "grant $1" >&3
  grants=$((grants + 1))
  # Written aside and moved into place: the node reads the file the moment it exists.
  nth_line "$work/stations.out" '^grant ' "$grants" | cut -d' ' -f2 > "$work/grant.tmp"
  mv "$work/grant.tmp" "$shared/grant.$1"
}
erl() {
  local name="$1"; shift
  podman run --rm --name "$name" --network host -v "$MACULA_BUILD:/macula:ro" -v "$root/scripts/interop:/interop:ro" \
    -v "$shared:/work" "$image" escript /interop/erlang_groups.escript /macula/_build/default/lib/macula \
    "$host" "$port" "$station" "$realm" "$realm_key" "$profile" /work "$@"
}
phpgroups_run() {
  php "$phpgroups" -station "$host:$port@$station" -profile "$profile" -realm "$realm" -realm-key "$realm_key" \
    -work "$shared" -prefix "$prefix" -topic "$topic" "$1"
}
# start_php MODE OUTFILE starts MODE in the background, its output in OUTFILE.
start_php() { phpgroups_run "$1" > "$2" 2>&1 & }
# node_of OUTFILE is the node id a started program printed.
node_of() { nth_line "$1" '^node ' 1 | awk '{print $2}'; }

echo "== $profile: the org's distributor in Erlang"
erl "${names[0]}" distribute "$org" "$org_key_id" 300 > "$work/dist.out" 2>&1 &
dist_pid=$!
php_pids+=("$dist_pid")
distributor="$(nth_line "$work/dist.out" '^node ' 1 | awk '{print $2}')"
echo "admit $distributor" >&3
nth_line "$work/stations.out" '^admitted ' 1 > /dev/null
touch "$shared/admitted.$distributor"
nth_line "$work/dist.out" '^serving' 1 > /dev/null

echo "== $profile: an Erlang member and a PHP member, each opening the other's sealed event"
erl "${names[1]}" member "$prefix" "$topic" > "$work/erlang.out" 2>&1 &
erl_pid=$!
php_pids+=("$erl_pid")
start_php member "$work/php.out"
member_pid=$!
php_pids+=("$member_pid")
grant "$(node_of "$work/erlang.out")"
grant "$(node_of "$work/php.out")"
status=0
wait "$member_pid" || status=1
wait "$erl_pid" || status=1
cat "$work/erlang.out" "$work/php.out"
[ "$status" = 0 ] || { echo "groups.sh: a member failed"; cat "$work/dist.out"; exit 1; }

echo "== $profile: a PHP node the distributor removed"
start_php stranger "$work/stranger.out"
stranger_pid=$!
php_pids+=("$stranger_pid")
stranger="$(node_of "$work/stranger.out")"
echo "$stranger" >> "$shared/removed"
grant "$stranger"
wait "$stranger_pid" || { cat "$work/stranger.out"; exit 1; }
cat "$work/stranger.out"

echo "== $profile: a PHP node presenting the grant minted for another node"
start_php impostor "$work/impostor.out"
impostor_pid=$!
php_pids+=("$impostor_pid")
grant "$(node_of "$work/impostor.out")"
wait "$impostor_pid" || { cat "$work/impostor.out"; exit 1; }
cat "$work/impostor.out"

echo "== $profile: sealed groups both ways"
