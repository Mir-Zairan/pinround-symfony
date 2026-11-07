#!/bin/sh
# Fails when compose.yaml is invalid, a service is missing, a long-running service has no healthcheck,
# a built or credential-holding service can pull its image, the workers do not consume every Messenger transport
# but `failed` exactly once with `outbox` alone in worker-outbox, a messenger:consume service does not wait for
# a healthy postgres (and the workers for a healthy valkey-state), or docker/minio/Dockerfile lacks pinned commits
# and digests, app's depends_on differs from what compose.yaml and compose.ci.yaml say, or the opt-in jaeger
# service is not digest-pinned, loopback-only and healthchecked.
# Only POSIX tools are used, so CI needs nothing beyond docker compose.
set -eu

cd "$(dirname "$0")/../.."

compose="docker compose --env-file .env --env-file .env.dev"

$compose config --quiet

services=$($compose config --services)
config=$($compose config)

for expected in app worker-outbox worker worker-heavy scheduler postgres valkey-state valkey-cache minio minio-init mailpit; do
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
for built in app worker-outbox worker worker-heavy scheduler minio minio-init; do
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

# The transport names after `messenger:consume` in a service's normalised command list.
consumed_by() {
    printf '%s\n' "$config" | awk -v svc="$1" '
        /^[a-z]/ { in_services = ($0 == "services:") }
        in_services && /^  [a-z0-9-]+:$/ { current = substr($1, 1, length($1) - 1); in_command = 0; consuming = 0 }
        in_services && current == svc && /^    [a-z_]+:/ { in_command = ($1 == "command:") }
        in_services && current == svc && in_command && /^      - / {
            arg = substr($0, 9)
            if (consuming && arg !~ /^-/) print arg
            if (arg == "messenger:consume") consuming = 1
        }
    '
}

# The transport keys of framework.messenger.transports in every environment (not when@test), `failed` left out:
# Messenger moves messages there itself, and an operator retries them by hand.
expected_transports=$(awk '
    /^[^ #]/ { top = $0 }
    top == "framework:" && /^        [a-z_]+:/ { in_transports = ($1 == "transports:"); next }
    top == "framework:" && in_transports && /^            [a-z_]+:/ { name = $1; sub(/:$/, "", name); if (name != "failed") print name }
' config/packages/messenger.yaml | sort)

consumed_transports=$( (consumed_by worker-outbox; consumed_by worker; consumed_by worker-heavy) | sort)

if [ -z "$expected_transports" ] || [ "$consumed_transports" != "$expected_transports" ]; then
    echo "compose-config-check: worker-outbox, worker and worker-heavy must consume each of [$(echo $expected_transports)] once; they consume [$(echo $consumed_transports)]" >&2
    exit 1
fi

# The relay alone, so a slow job on another transport never delays it (ADR-0033).
outbox_worker_transports=$(consumed_by worker-outbox)
if [ "$outbox_worker_transports" != "outbox" ]; then
    echo "compose-config-check: worker-outbox must consume only outbox; it consumes [$(echo $outbox_worker_transports)]" >&2
    exit 1
fi

# Whether a service's depends_on lists the given service with condition: service_healthy, so it starts only once
# that service answers; service_started would let a worker begin consuming before PostgreSQL or Valkey is ready.
depends_on_healthy() {
    printf '%s\n' "$config" | awk -v svc="$1" -v dep="$2" '
        /^[a-z]/ { in_services = ($0 == "services:") }
        in_services && /^  [a-z0-9-]+:$/ { current = substr($1, 1, length($1) - 1); in_depends = 0; in_dep = 0 }
        in_services && current == svc && /^    [a-z_]+:/ { in_depends = ($1 == "depends_on:"); in_dep = 0 }
        in_services && current == svc && in_depends && /^      [a-z0-9-]+:$/ { in_dep = ($0 == "      " dep ":") }
        in_services && current == svc && in_dep && $0 == "        condition: service_healthy" { healthy = 1 }
        END { exit !healthy }
    '
}

# The outbox and failed transports are PostgreSQL tables, and a scheduled job records its events in the outbox.
for consumer in worker-outbox worker worker-heavy scheduler; do
    if ! depends_on_healthy "$consumer" postgres; then
        echo "compose-config-check: service '$consumer' must depend on postgres with condition: service_healthy" >&2
        exit 1
    fi
done

for worker in worker-outbox worker worker-heavy; do
    if ! depends_on_healthy "$worker" valkey-state; then
        echo "compose-config-check: service '$worker' must depend on valkey-state with condition: service_healthy" >&2
        exit 1
    fi
done

# The "name:condition" lines of app's depends_on in a normalised config on stdin.
app_dependencies() {
    awk '
        /^[a-z]/ { in_services = ($0 == "services:") }
        in_services && /^  [a-z0-9-]+:$/ { current = substr($1, 1, length($1) - 1); in_depends = 0 }
        in_services && current == "app" && /^    [a-z_]+:/ { in_depends = ($1 == "depends_on:") }
        in_services && current == "app" && in_depends && /^      [a-z0-9-]+:$/ { dep = substr($1, 1, length($1) - 1) }
        in_services && current == "app" && in_depends && /^        condition: / { print dep ":" $2 }
    ' | sort
}

# app waits for every service its first request needs; a dependency added to compose.yaml has to be weighed
# against compose.ci.yaml, which replaces the list for the e2e job (it must not start minio, minio-init or mailpit).
app_all=$(printf '%s\n' "$config" | app_dependencies)
app_all_expected=$(printf '%s\n' minio-init:service_completed_successfully mailpit:service_healthy postgres:service_healthy valkey-cache:service_healthy valkey-state:service_healthy | sort)
if [ "$app_all" != "$app_all_expected" ]; then
    echo "compose-config-check: app in compose.yaml must depend on [$(echo $app_all_expected)]; it depends on [$(echo $app_all)]. Update compose.ci.yaml too." >&2
    exit 1
fi

app_ci=$($compose -f compose.yaml -f compose.ci.yaml config | app_dependencies)
app_ci_expected=$(printf '%s\n' postgres:service_healthy valkey-cache:service_healthy valkey-state:service_healthy | sort)
if [ "$app_ci" != "$app_ci_expected" ]; then
    echo "compose-config-check: app with compose.ci.yaml must depend on exactly [$(echo $app_ci_expected)]; it depends on [$(echo $app_ci)]" >&2
    exit 1
fi

dockerfile=docker/minio/Dockerfile

for arg in MINIO_COMMIT MC_COMMIT; do
    if ! grep -Eq "^ARG $arg=[0-9a-f]{40}$" "$dockerfile"; then
        echo "compose-config-check: $dockerfile has no 40-hex pinned ARG $arg" >&2
        exit 1
    fi
done

unpinned=$(grep '^FROM ' "$dockerfile" | grep -v '@sha256:' || true)
if [ -n "$unpinned" ]; then
    echo "compose-config-check: base image not pinned by digest in $dockerfile: $unpinned" >&2
    exit 1
fi

# jaeger sits in the opt-in `observability` profile: absent from a plain config, present with the profile.
if printf '%s\n' "$services" | grep -qx jaeger; then
    echo "compose-config-check: service 'jaeger' must not start without --profile observability" >&2
    exit 1
fi

profile_config=$($compose --profile observability config)

if ! $compose --profile observability config --services | grep -qx jaeger; then
    echo "compose-config-check: service 'jaeger' is missing from the observability profile" >&2
    exit 1
fi

# The lines of the jaeger service in the normalised config.
jaeger_config=$(printf '%s\n' "$profile_config" | awk '
    /^[a-z]/ { in_services = ($0 == "services:") }
    in_services && /^  [a-z0-9-]+:$/ { current = substr($1, 1, length($1) - 1) }
    in_services && current == "jaeger" { print }
')

if ! printf '%s\n' "$jaeger_config" | grep -Eq '^    image: [^ @]+@sha256:[0-9a-f]{64}$'; then
    echo "compose-config-check: jaeger image is not pinned by a 64-hex @sha256 digest" >&2
    exit 1
fi

# Every published port must be bound to loopback, so Jaeger's UI and OTLP port are not open to the network.
jaeger_ports=$(printf '%s\n' "$jaeger_config" | grep -c '^        target: ' || true)
jaeger_loopback_ports=$(printf '%s\n' "$jaeger_config" | grep -c '^        host_ip: 127\.0\.0\.1$' || true)
if [ "$jaeger_ports" -lt 1 ] || [ "$jaeger_ports" != "$jaeger_loopback_ports" ]; then
    echo "compose-config-check: every jaeger port must have host_ip 127.0.0.1 ($jaeger_loopback_ports of $jaeger_ports do)" >&2
    exit 1
fi

if ! printf '%s\n' "$jaeger_config" | grep -q '^    healthcheck:'; then
    echo "compose-config-check: no healthcheck for: jaeger" >&2
    exit 1
fi

echo "compose-config-check: ok"
