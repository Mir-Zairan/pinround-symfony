#!/bin/sh
# Fails when compose.yaml is invalid, a service is missing, or a long-running service has no healthcheck.
# Only POSIX tools are used, so CI needs nothing beyond docker compose.
set -eu

cd "$(dirname "$0")/../.."

compose="docker compose --env-file .env --env-file .env.dev"

$compose config --quiet

services=$($compose config --services)

for expected in app worker scheduler postgres valkey-state valkey-cache minio minio-init mailpit; do
    if ! printf '%s\n' "$services" | grep -qx "$expected"; then
        echo "compose-config-check: service '$expected' is missing from compose.yaml" >&2
        exit 1
    fi
done

# In the normalised `config` output, services sit at two spaces and their keys at four.
without_healthcheck=$($compose config | awk '
    /^[a-z]/ { in_services = ($0 == "services:") }
    in_services && /^  [a-z0-9-]+:$/ { if (name != "" && !has_check) print name; name = substr($1, 1, length($1) - 1); has_check = 0 }
    in_services && /^    healthcheck:/ { has_check = 1 }
    END { if (name != "" && !has_check) print name }
')

# minio-init is a one-shot that exits 0, so it has no healthcheck by design.
without_healthcheck=$(printf '%s\n' "$without_healthcheck" | grep -vx 'minio-init' || true)

if [ -n "$without_healthcheck" ]; then
    echo "compose-config-check: no healthcheck for: $without_healthcheck" >&2
    exit 1
fi

# Images that hold credentials or are built here must never be pulled from a registry.
for built in app worker scheduler minio minio-init; do
    policy=$($compose config | awk -v svc="$built" '
        /^[a-z]/ { in_services = ($0 == "services:") }
        in_services && /^  [a-z0-9-]+:$/ { current = substr($1, 1, length($1) - 1) }
        in_services && current == svc && /^    pull_policy:/ { print $2 }
    ')
    if [ "$policy" != "never" ]; then
        echo "compose-config-check: service '$built' needs pull_policy: never (found '${policy:-none}')" >&2
        exit 1
    fi
done

dockerfile=docker/minio/Dockerfile

for arg in MINIO_COMMIT MC_COMMIT; do
    if ! grep -q "^ARG $arg=" "$dockerfile"; then
        echo "compose-config-check: $dockerfile has no pinned ARG $arg" >&2
        exit 1
    fi
done

unpinned=$(grep '^FROM ' "$dockerfile" | grep -v '@sha256:' || true)
if [ -n "$unpinned" ]; then
    echo "compose-config-check: base image not pinned by digest in $dockerfile: $unpinned" >&2
    exit 1
fi

echo "compose-config-check: ok"
