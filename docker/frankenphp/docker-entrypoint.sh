#!/bin/sh
set -e

# A clean clone has no vendor/ on the bind mount; install once so `make up` works unaided.
if [ "$1" = "frankenphp" ] && [ "${APP_ENV:-dev}" = "dev" ] && [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --prefer-dist
fi

exec "$@"
