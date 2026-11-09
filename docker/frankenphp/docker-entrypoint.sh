#!/bin/sh
set -e

# Never start prod with an empty or placeholder APP_SECRET, Mercure key or field encryption key: anyone could forge
# signed tokens and cookies, or read the stored secrets with the public dev key. A change-me entry anywhere in a
# comma list counts too, and so does a committed dev or test field key under any id: those keys are base64 of text
# that starts with "change-me", so their base64 starts with Y2hhbmdlLW1l.
# This runs for every command (app, worker, scheduler, migrate), not only the web server.
if [ "${APP_ENV:-dev}" = "prod" ]; then
    for key_name in APP_SECRET MERCURE_PUBLISHER_JWT_KEY MERCURE_SUBSCRIBER_JWT_KEY FIELD_ENCRYPTION_KEYS FIELD_ENCRYPTION_CURRENT_KEY_ID; do
        key_value=$(printenv "$key_name" || true)
        case "$key_value" in
            ""|change-me*|*,change-me*|*[[:space:]]change-me*|*:*Y2hhbmdlLW1l*)
                echo "Refusing to start: $key_name is empty or holds a change-me placeholder. Set a real value." >&2
                exit 1
                ;;
        esac
    done
fi

# A clean clone has no vendor/ or assets/vendor/ (the vendored JS packages) on the bind mount; install once
# so `make up` works unaided. The composer scripts run importmap:install.
if [ "$1" = "frankenphp" ] && [ "${APP_ENV:-dev}" = "dev" ] && { [ ! -f vendor/autoload_runtime.php ] || [ ! -d assets/vendor ]; }; then
    composer install --no-interaction --prefer-dist
fi

exec "$@"
