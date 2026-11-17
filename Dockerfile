# syntax=docker/dockerfile:1

# One image for every role (ADR-0030); PHP 8.5 runs in the image while composer.json allows >=8.4 (ADR-0003).
FROM dunglas/frankenphp:1-php8.5-bookworm AS base

# Matches the host user so files written to the bind mount stay owned by the developer.
ARG APP_UID=1000
ARG APP_GID=1000

WORKDIR /app

# sodium and opcache are already in the base image.
RUN install-php-extensions pdo_pgsql intl zip apcu redis

# The vips command-line tool re-encodes uploads in worker-media (VipsImageEncoder, ADR-0011); in the base stage so
# dev, prod and every worker role have it. Pinned to bookworm's 8.14.1, whose WebP and thumbnail behaviour the encoder
# and its tests rely on; the wildcard lets Debian's security and point releases of 8.14.1 in. It pulls every loader
# libvips is built with (ImageMagick, librsvg, poppler, OpenSlide, libheif, libjxl and more); only pngload, jpegload
# and webpload are ever given an upload (media-pipeline.md, Processing worker).
RUN apt-get update \
    && apt-get install -y --no-install-recommends 'libvips-tools=8.14.1-*' \
    && rm -rf /var/lib/apt/lists/*

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

# Run as a non-root user. Caddy must bind 80 and 443 inside the container; the file
# capability on the binary allows that without root (the capability is in Docker's default set).
# The host ids may already exist in the image (macOS gid 20 is dialout), so the app group may share a gid; COPY --chown=app:app needs the names.
# Root ids (0) would make the app run as root, so they fall back to 1000.
RUN app_uid=${APP_UID}; app_gid=${APP_GID}; \
    if [ "$app_uid" = "0" ]; then app_uid=1000; fi; \
    if [ "$app_gid" = "0" ]; then app_gid=1000; fi; \
    groupadd --non-unique --gid "$app_gid" app \
    && useradd --non-unique --uid "$app_uid" --gid app --create-home --shell /bin/bash app \
    && setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy /app/var \
    && chown -R app:app /data /config /app

ENV COMPOSER_ALLOW_SUPERUSER=0

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
# Through Caddy to the app, from loopback (an internal caller, so not refused). HTTPS, because Caddy answers plain HTTP
# with a redirect that curl -f counts as success; -k, because the local CA is not in the trust store (skip_install_trust);
# --connect-to keeps SERVER_NAME's TLS name and Host while connecting to this container. SERVER_NAME must be one host name.
HEALTHCHECK --interval=10s --timeout=3s --start-period=60s --retries=5 \
    CMD curl -fsSk --connect-to ::127.0.0.1: "https://${SERVER_NAME:-localhost}/health/live" -o /dev/null || exit 1

USER app

FROM base AS dev

# No APP_ENV here: a real env var would beat PHPUnit's APP_ENV=test; .env supplies dev.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --chown=app:app docker/frankenphp/conf.d/10-dev.ini $PHP_INI_DIR/conf.d/10-dev.ini

FROM base AS prod

ENV APP_ENV=prod
ENV APP_DEBUG=0
# Caddy's admin API off (docker/frankenphp/Caddyfile): no config change and no /metrics endpoint at run time.
ENV CADDY_ADMIN=off
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --chown=app:app docker/frankenphp/conf.d/10-prod.ini $PHP_INI_DIR/conf.d/10-prod.ini

COPY --chown=app:app composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY --chown=app:app . .
# Cache warm-up needs these variables to exist; the throwaway values are scoped to that one
# command, so nothing is baked into the image. Real values come from the runtime environment.
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && DATABASE_URL="postgresql://build:build@localhost:5432/build?serverVersion=18" \
       MAILER_DSN="null://null" \
       MESSENGER_TRANSPORT_DSN="in-memory://" \
       REDIS_CACHE_DSN="redis://localhost:6379" \
       DEFAULT_URI="http://localhost" \
       MERCURE_URL="http://localhost/.well-known/mercure" \
       MERCURE_PUBLIC_URL="http://localhost/.well-known/mercure" \
       composer run-script --no-dev post-install-cmd \
    && chmod +x bin/console \
    && php bin/console asset-map:compile
