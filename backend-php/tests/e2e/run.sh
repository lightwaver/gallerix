#!/usr/bin/env bash
# End-to-end tests: real HTTP requests against the PHP backend with Azurite (Azure Storage emulator).
# Requires podman (or docker: DOCKER=docker ./run.sh). Usage: backend-php/tests/e2e/run.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
BACKEND="$(cd "$HERE/../.." && pwd)"
CLI="${DOCKER:-podman}"
NET=gallerix-e2e-$$

"$CLI" build -q -t gallerix-e2e -f "$HERE/Containerfile" "$HERE" >/dev/null
"$CLI" network create "$NET" >/dev/null
cleanup() { "$CLI" rm -f "$NET-azurite" >/dev/null 2>&1 || true; "$CLI" network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT

"$CLI" run -d --name "$NET-azurite" --network "$NET" --network-alias azurite \
  mcr.microsoft.com/azure-storage/azurite azurite-blob --blobHost 0.0.0.0 --loose --skipApiVersionCheck >/dev/null
sleep 3
"$CLI" run --rm --network "$NET" -e AZURITE_HOST=azurite \
  -v "$BACKEND":/src:ro -v "$HERE":/e2e:ro gallerix-e2e bash /e2e/scenarios.sh | tee /dev/stderr | grep '=== .* passed, 0 failed' >/dev/null
