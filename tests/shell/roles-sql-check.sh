#!/bin/sh
# Fails unless docker/postgres/roles.sql refuses to run when a password is missing or empty,
# and leaves roles, password hashes, owners and grants exactly as they were.
# ROLES_PSQL is the admin psql command for the target database; the default is the compose
# postgres container. CI points it at the service: ROLES_PSQL="psql -h 127.0.0.1 -U pinround -d pinround_test".
set -eu

cd "$(dirname "$0")/../.."

psql=${ROLES_PSQL:-docker compose --env-file .env --env-file .env.dev exec -T postgres psql -U pinround -d pinround_test}

# Setting a password writes a new salted hash, so an unchanged rolpassword proves no ALTER ROLE ran.
state_query="
SELECT 'role ' || rolname || ' ' || concat_ws(',', rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls, rolcanlogin, rolpassword)
FROM pg_authid WHERE rolname LIKE 'pinround%'
UNION ALL SELECT 'db ' || datname || ' ' || datdba::regrole::text || ' ' || coalesce(datacl::text, '') FROM pg_database WHERE datname = current_database()
UNION ALL SELECT 'schema ' || nspowner::regrole::text || ' ' || coalesce(nspacl::text, '') FROM pg_namespace WHERE nspname = 'public'
UNION ALL SELECT 'rel ' || relname || ' ' || relowner::regrole::text || ' ' || coalesce(relacl::text, '') FROM pg_class WHERE relnamespace = 'public'::regnamespace
UNION ALL SELECT 'defacl ' || defaclrole::regrole::text || ' ' || defaclacl::text FROM pg_default_acl
ORDER BY 1"

before=$($psql -X -A -t -c "$state_query")

expect_refusal() {
    case_name=$1
    shift
    if output=$($psql -X "$@" < docker/postgres/roles.sql 2>&1); then
        echo "roles-sql-check: roles.sql ran with $case_name" >&2
        exit 1
    fi
    if ! printf '%s\n' "$output" | grep -q 'roles.sql needs -v app_password'; then
        echo "roles-sql-check: roles.sql failed with $case_name, but not on the password check: $output" >&2
        exit 1
    fi
    after=$($psql -X -A -t -c "$state_query")
    if [ "$after" != "$before" ]; then
        echo "roles-sql-check: roles.sql changed the database with $case_name" >&2
        exit 1
    fi
}

variables="app_password migrator_password retention_password readonly_password"

expect_refusal "no passwords"

for target in $variables; do
    set --
    for variable in $variables; do
        if [ "$variable" != "$target" ]; then
            set -- "$@" -v "$variable=check-only"
        fi
    done
    expect_refusal "$target missing" "$@"
    expect_refusal "$target empty" "$@" -v "$target="
done

echo "roles-sql-check: ok"
