#!/bin/sh
# Fails when compose.yaml is invalid or one of the eight services is missing.
set -eu

cd "$(dirname "$0")/../.."

docker compose --env-file .env --env-file .env.dev config --quiet

services=$(docker compose --env-file .env --env-file .env.dev config --services)

for expected in app worker scheduler postgres valkey-state valkey-cache minio mailpit; do
    if ! printf '%s\n' "$services" | grep -qx "$expected"; then
        echo "compose-config-check: service '$expected' is missing from compose.yaml" >&2
        exit 1
    fi
done

echo "compose-config-check: ok"
