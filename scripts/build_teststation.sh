#!/usr/bin/env bash
# Builds macula-go's teststation (in-process stations for the offline suite)
# at the tag in abi/MACULA_GO_REF, to build/teststation. Needs go.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
read -r tag _ < "$root/abi/MACULA_GO_REF"
mkdir -p "$root/build"
GOBIN="$root/build" go install "github.com/macula-io/macula-go/teststation/cmd/teststation@$tag"
