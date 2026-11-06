# syntax=docker/dockerfile:1

# One image for every role (ADR-0030); PHP 8.5 runs in the image while composer.json allows >=8.4 (ADR-0003).
FROM dunglas/frankenphp:1-php8.5-bookworm AS base

# Matches the host user so files written to the bind mount stay owned by the developer.
ARG APP_UID=1000
ARG APP_GID=1000

WORKDIR /app

# sodium and opcache are already in the base image.
RUN install-php-extensions pdo_pgsql intl zip apcu redis

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

# Run as a non-root user. Caddy must bind 80 and 443 inside the container; the file
# capability on the binary allows that without root (the capability is in Docker's default set).
RUN groupadd --gid ${APP_GID} app \
    && useradd --uid ${APP_UID} --gid ${APP_GID} --create-home --shell /bin/bash app \
    && setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy /app/var \
    && chown -R app:app /data /config /app

ENV FRANKENPHP_WORKER=1
ENV COMPOSER_ALLOW_SUPERUSER=0

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
HEALTHCHECK --interval=10s --timeout=3s --start-period=60s --retries=5 \
    CMD curl -fsS http://localhost:2019/metrics -o /dev/null || exit 1

USER app

FROM base AS dev

# No APP_ENV here: a real env var would beat PHPUnit's APP_ENV=test; .env supplies dev.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --chown=app:app docker/frankenphp/conf.d/10-dev.ini $PHP_INI_DIR/conf.d/10-dev.ini

FROM base AS prod

ENV APP_ENV=prod
ENV APP_DEBUG=0
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --chown=app:app docker/frankenphp/conf.d/10-prod.ini $PHP_INI_DIR/conf.d/10-prod.ini

COPY --chown=app:app composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY --chown=app:app . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && chmod +x bin/console
