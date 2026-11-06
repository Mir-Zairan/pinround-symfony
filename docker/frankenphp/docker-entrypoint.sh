#!/bin/sh
set -e

if [ "$1" = "frankenphp" ]; then
    # A clean clone has no vendor/ on the bind mount; install once so `make up` works unaided.
    if [ "${APP_ENV:-dev}" = "dev" ] && [ ! -f vendor/autoload_runtime.php ]; then
        composer install --no-interaction --prefer-dist
    fi

    # Never serve prod with empty or placeholder Mercure keys: anyone could forge subscriber tokens.
    if [ "${APP_ENV:-dev}" = "prod" ]; then
        for key_name in MERCURE_PUBLISHER_JWT_KEY MERCURE_SUBSCRIBER_JWT_KEY; do
            key_value=$(printenv "$key_name" || true)
            case "$key_value" in
                ""|change-me*)
                    echo "Refusing to start: $key_name is empty or a change-me placeholder. Set a real key." >&2
                    exit 1
                    ;;
            esac
        done
    fi
fi

exec "$@"
