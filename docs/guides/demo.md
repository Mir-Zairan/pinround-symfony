# Run the demo with make demo

What `make demo` does, what it needs, and how to publish new ready-made images after a change to the dev images.

## What it does
`make demo` runs `.claude/skills/pinround-setup/pinround-setup.sh demo`:
1. Writes a random `DEMO_PASSWORD` to `.env.dev.local` if there is none, and turns demo mode on there (`PINROUND_DEMO_SKIP_2FA=1`, dev only, [ADR-0049](../03-adr/0049-demo-switch-for-two-step-login.md)) before the first start, so no restart is needed.
2. Pulls the ready-made dev images by digest from `ghcr.io/mir-zairan` (amd64 and arm64) and tags them `pinround-app:dev` and `pinround-minio:dev`. `compose.yaml` keeps `pull_policy: never`: compose itself never pulls these names.
3. Starts the app, applies the database roles and migrations (not the test database), starts the rest and loads the demo data.
4. Prints the address and who to log in as. It never prints the password; `grep DEMO_PASSWORD .env.dev.local` shows it.

Measured on 2026-11-22 in an empty Docker (Docker in Docker, home internet line): 248 seconds from `git clone` to the login page, with the three showcase tickets. The pull takes about 60 seconds, the first app start (Composer install) about 90, the demo data about 70. A full `make up` that builds the images took 630 seconds.

## When it builds instead
The app image runs as uid 1000. Docker Desktop on macOS maps file owners on its mounts, so it always uses the ready images there. On Linux it uses them only when `id -u` is 1000; anyone else builds the images, which takes 7 to 8 minutes the first time. It also builds when the pull fails.

## Publish new ready-made images
The dev app image holds no app code (the clone is mounted), only PHP, its extensions, vips, Composer and the files under `docker/frankenphp/`. Publish a new one after a change to the `Dockerfile`, `docker/frankenphp/` or `docker/minio/`:
1. Once per machine: `docker run --privileged --rm tonistiigi/binfmt:qemu-v9.2.2 --install arm64` (until the next reboot) and `docker buildx create --name pinround-multi --driver docker-container`.
2. Log in with a token that has `write:packages`: `gh auth refresh -h github.com -s write:packages`, then pipe the token (`gh auth token`, or `gh config get -h github.com oauth_token` on gh 2.4) to `docker login ghcr.io -u <user> --password-stdin`.
3. Build and push, with the source label so the package links to the repository:
   ```bash
   SRC=https://github.com/Mir-Zairan/pinround-symfony
   docker buildx build --builder pinround-multi --platform linux/amd64,linux/arm64 --target dev \
     --build-arg APP_UID=1000 --build-arg APP_GID=1000 --label org.opencontainers.image.source=$SRC \
     -t ghcr.io/mir-zairan/pinround-app:dev-YYYYMMDD --push .
   docker buildx build --builder pinround-multi --platform linux/amd64,linux/arm64 \
     --label org.opencontainers.image.source=$SRC -t ghcr.io/mir-zairan/pinround-minio:<MINIO_RELEASE> --push docker/minio
   ```
   The arm64 app image builds its PHP extensions under emulation: about 30 minutes. MinIO cross-compiles and takes about 4.
4. Copy the two digests (`docker buildx imagetools inspect <tag>`) into `ready_app_image` and `ready_minio_image` in `pinround-setup.sh`, then check a cold run.
5. A new package on GitHub starts private: set it to Public in its settings, or `make demo` falls back to building.
