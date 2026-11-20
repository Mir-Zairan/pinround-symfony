#!/bin/sh
# Fails when the Caddyfile does not load in the prod image, or loads there with the dev-only HTTPS proxy to MinIO,
# or when the dev image with docker/frankenphp/dev.d mounted (as compose.yaml does) lacks that proxy: a site on :9443
# that sends everything to minio:9000 with no Host header set, so presigned signatures still match.
# Usage: caddy-config-check.sh <prod image> <dev image>. Only POSIX tools beside docker.
set -eu

if [ "$#" -ne 2 ]; then
    echo "usage: caddy-config-check.sh <prod image> <dev image>" >&2
    exit 2
fi
prod_image=$1
dev_image=$2

cd "$(dirname "$0")/../.."

# The hub's token audience has no default in the Caddyfile; prod sets it at run time.
adapt() {
    docker run --rm --network none -e MERCURE_PUBLIC_URL=https://pinround.example/.well-known/mercure "$@" \
        --entrypoint frankenphp "$image" adapt --config /etc/caddy/Caddyfile
}

image=$prod_image
if ! prod_config=$(adapt); then
    echo "caddy-config-check: the Caddyfile does not load in $prod_image" >&2
    exit 1
fi
if printf '%s' "$prod_config" | grep -q '":9443"'; then
    echo "caddy-config-check: $prod_image listens on :9443; the HTTPS proxy to MinIO is for the dev stack only" >&2
    exit 1
fi
if printf '%s' "$prod_config" | grep -q 'minio:9000'; then
    echo "caddy-config-check: $prod_image proxies to minio:9000; the HTTPS proxy to MinIO is for the dev stack only" >&2
    exit 1
fi

image=$dev_image
if ! dev_config=$(adapt -v "$PWD/docker/frankenphp/dev.d:/etc/caddy/dev.d:ro"); then
    echo "caddy-config-check: the Caddyfile with docker/frankenphp/dev.d does not load in $dev_image" >&2
    exit 1
fi
# adapt prints one line of JSON; each server's block starts at "srvN":.
storage_server=$(printf '%s\n' "$dev_config" | awk '{ gsub(/"srv[0-9]+":/, "\n&"); print }' | grep '"listen":\[":9443"\]' || true)
if [ -z "$storage_server" ]; then
    echo "caddy-config-check: $dev_image with docker/frankenphp/dev.d has no server on :9443" >&2
    exit 1
fi
if ! printf '%s' "$storage_server" | grep -q '"handler":"reverse_proxy"' \
    || ! printf '%s' "$storage_server" | grep -q '"upstreams":\[{"dial":"minio:9000"}\]'; then
    echo "caddy-config-check: the :9443 server must proxy to minio:9000 only" >&2
    exit 1
fi
if printf '%s' "$storage_server" | grep -q '"Host"'; then
    echo "caddy-config-check: the :9443 server sets a Host header; presigned signatures cover the Host the browser sent" >&2
    exit 1
fi
if printf '%s' "$storage_server" | grep -q '"handler":"rewrite"'; then
    echo "caddy-config-check: the :9443 server rewrites the path; presigned signatures cover /{bucket}/{key}" >&2
    exit 1
fi

echo "caddy-config-check: ok"
