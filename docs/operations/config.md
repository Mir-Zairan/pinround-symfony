# Configuration reference

One page that lists every environment variable pinround reads: its default, who reads it, whether it is secret, and what it does.

## Index
- [How to read this page](#how-to-read-this-page) — columns, service names, and the check that keeps the page complete
- [Where secrets come from](#where-secrets-come-from) — the rule for real values
- [Rules for prod](#rules-for-prod) — what the prod environment must provide
- [App and Symfony](#app-and-symfony) — environment, secret, share folder, public URL
- [Database](#database) — the three connection URLs and the compose database settings
- [Valkey and messaging](#valkey-and-messaging) — cache, state (sessions, login counters) and queue DSNs
- [Mail](#mail) — the mailer DSN
- [Mercure](#mercure) — hub keys and the public hub URL
- [Object storage](#object-storage) — health endpoint, public URL, MinIO credentials
- [Tracing](#tracing) — the OpenTelemetry settings
- [Web server and runtime](#web-server-and-runtime) — FrankenPHP and Caddy settings
- [Docker and compose host settings](#docker-and-compose-host-settings) — host ports, user ids, database role passwords
- [Test-only](#test-only) — variables only tests read
- [CI only](#ci-only) — variables only the GitHub workflow sets

## How to read this page
- **Default** is the committed value. "Empty on purpose" means the committed value is empty and another file or the platform sets it. "None, required in prod" means no file the prod image holds sets it.
- **Read by** names the compose services: `app` (the web server), `worker-outbox`, `worker`, `worker-heavy`, `scheduler` and `migrate` (the one-off migration run). They share one image. "compose only" means `compose.yaml` or the `Makefile` reads it, not PHP. "test only" and "CI only" are as they say.
- **Secret** is "yes" when the value must never be in the repo.
- Dev and test values live in `.env.dev` and `.env.test`. `.env` holds the shared defaults and empty values only. `.env.local` is git-ignored and per machine; this page does not list it.
- `ConfigDocTest` fails when a variable set in `.env`, `.env.dev` or `.env.test` is missing from this page. Commented-out example lines do not count. It does not check the other files named here, so keep those rows in step by hand.

## Where secrets come from
Real secrets never enter the repo ([ADR-0027](../03-adr/0027-secrets-and-encryption.md)). They come from the Symfony secrets vault, or from the platform's secret store injected as environment variables. The committed `change-me-dev-only-...` values are dev placeholders that grant nothing outside a developer's machine. The prod entrypoint refuses to start with such a value for the three keys below.

## Rules for prod
- The prod image sets `APP_ENV=prod`, `APP_DEBUG=0` and `CADDY_ADMIN=off`. With the admin API off, nothing can change the running Caddy config and there is no `/metrics` endpoint.
- `APP_SECRET` must be a plain environment variable, not a Symfony secret. The entrypoint guard reads only the environment. The same guard refuses to start (for every command, so workers and the scheduler too) when `APP_SECRET`, `MERCURE_PUBLISHER_JWT_KEY` or `MERCURE_SUBSCRIBER_JWT_KEY` is empty or starts with `change-me`.
- Prod must provide `DATABASE_URL`, `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN`, `DEFAULT_URI`, `REDIS_CACHE_DSN`, `STATE_VALKEY_DSN` and the two Mercure keys. Nothing in the prod image supplies them. The image build uses throwaway values for the cache warm-up only; none is kept.
- `MESSENGER_TRANSPORT_DSN` points at the state Valkey and names no stream: no path after the host (`redis://valkey-state:6379`, not `.../messages` or `.../1`) and no `stream` query parameter. Otherwise the four `async_*` queues merge into one stream. The `dsn_without_stream` env processor makes `worker` and `worker-heavy` fail at start on such a DSN. It also fails on a comma that does not begin another host, so a comma in the password must be written `%2C`. See [ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md).
- `STATE_VALKEY_DSN` points at the state Valkey (`noeviction`), never at the cache one: sessions and the login counters live there, and an LRU eviction would log people out and hand an attacker fresh login tries. When it cannot be reached, logins fail (no unthrottled tries) and pages that need a session fail too.
- The session cookie is `__Host-pinround`: browsers keep it only over HTTPS, so pinround must be served over HTTPS (dev uses `https://localhost`).
- Set `OBJECT_STORAGE_ENDPOINT` to the storage's address on the app's network. `/health/ready` fails while it is empty.
- `/health/live` and `/health/ready` answer only loopback and private client IPs. Anyone else gets the 404 of a missing route. Behind a reverse proxy or load balancer on a private network every client looks private, so configure trusted proxies or block `/health/*` at the edge. Symfony reads trusted proxies from `SYMFONY_TRUSTED_PROXIES` and `SYMFONY_TRUSTED_HEADERS` (the framework defaults; the repo sets neither). Caddy has its own `trusted_proxies` option if it sits behind a proxy. This is open work for phase 12.
- Prod has no `MIGRATOR_DATABASE_URL`. The `migrate` container gets its own `DATABASE_URL` for the migrator role. See [operations.md](../02-architecture/operations.md#configuration).
- Prod logs `info` and above to stdout as JSON. No variable changes that.

## App and Symfony
| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `APP_ENV` | `dev` in `.env`; `prod` is set by the prod image | app, all workers, migrate | no | Picks the Symfony environment. The dev image sets none, so `.env` gives `dev` and tests can set `test`. |
| `APP_DEBUG` | none; the prod image sets `0` | app, all workers, migrate | no | Turns Symfony debug mode on or off. Not in an env file. Unset means on in dev and test. |
| `APP_SECRET` | empty on purpose in `.env`; dev placeholder in `.env.dev` | app, all workers, migrate | yes | Signs tokens and cookies. None, required in prod, as a plain environment variable. |
| `APP_SHARE_DIR` | `var/share` | app, all workers, migrate | no | Folder for data shared between app versions. |
| `DEFAULT_URI` | `http://localhost` in `.env.dev` and `.env.test`; none, required in prod | app, all workers, migrate | no | The public base URL, used to build links in commands, workers and mail. |
| `KERNEL_CLASS` | `App\Kernel` in `.env.test` | test only | no | The kernel class the Symfony test cases boot. |

## Database
Dev and test use the app role for the web and workers, and the migrator role for migrations through the `migrator` Doctrine connection. The passwords in the URLs must match the ones `make db-roles` sets ([Docker and compose host settings](#docker-and-compose-host-settings)).

| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `DATABASE_URL` | dev placeholder in `.env.dev` and `.env.test`; none, required in prod | app, all workers, migrate | yes | Connection URL of the app role `pinround_app`. In prod, `migrate` gets its own value for the migrator role. |
| `MIGRATOR_DATABASE_URL` | dev placeholder in `.env.dev` and `.env.test`; not used in prod | app container in dev and test (migrations) | yes | Connection URL of the migrator role `pinround_migrator`, for migrations. |
| `READONLY_DATABASE_URL` | dev placeholder in `.env.test`; CI sets it on the test steps | test only | yes | Connection URL of the read-only role, so tests can prove what that role is refused. No web or worker gets it. |
| `POSTGRES_USER` | `pinround` | postgres, compose only | no | Name of the database admin account. |
| `POSTGRES_PASSWORD` | `pinround` | postgres, compose only | yes | Admin account password; a dev placeholder. Never reuse it in prod. |
| `POSTGRES_DB` | `pinround` | postgres, compose only | no | Name of the first database. |
| `TEST_TOKEN` | none | test only | no | Set by ParaTest. Doctrine adds it to the `_test` database name. |

## Valkey and messaging
| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `REDIS_CACHE_DSN` | empty on purpose in `.env`; `redis://valkey-cache:6379` in `.env.dev` and `.env.test`; none, required in prod | app, all workers, migrate | yes, when it holds a password | DSN of the cache Valkey, read by `cache.app`. |
| `STATE_VALKEY_DSN` | empty on purpose in `.env`; `redis://valkey-state:6379` in `.env.dev`; not set in tests (they use `TEST_STATE_VALKEY_DSN`); none, required in prod | app, all workers, migrate | yes, when it holds a password | DSN of the state Valkey for sessions (key prefix `session:`) and the login counters (prefix `login_throttling:`). |
| `MESSENGER_TRANSPORT_DSN` | `redis://valkey-state:6379` in `.env.dev`; `in-memory://` in `.env.test`; none, required in prod | app, all workers | yes, when it holds a password | DSN of the state Valkey that holds the queues. See [Rules for prod](#rules-for-prod) for the path, stream and comma rules. |

## Mail
| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `MAILER_DSN` | `smtp://mailpit:1025` in `.env.dev`; `null://null` in `.env.test`; none, required in prod | app, all workers | yes, in prod (it holds the SMTP login) | Where the mailer sends mail. Dev mail goes to Mailpit. |

## Mercure
| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `MERCURE_PUBLISHER_JWT_KEY` | empty on purpose in `.env`; dev placeholder in `.env.dev` and `compose.yaml`; none, required in prod | app (Caddy hub) | yes | Key that signs publish tokens. Prod refuses an empty or `change-me` value. |
| `MERCURE_PUBLISHER_JWT_ALG` | `HS256` | app (Caddy hub) | no | Signing algorithm of the publisher key. |
| `MERCURE_SUBSCRIBER_JWT_KEY` | empty on purpose in `.env`; dev placeholder in `.env.dev` and `compose.yaml`; none, required in prod | app (Caddy hub) | yes | Key that checks subscribe tokens. Prod refuses an empty or `change-me` value. |
| `MERCURE_SUBSCRIBER_JWT_ALG` | `HS256` | app (Caddy hub) | no | Algorithm of the subscriber key. |
| `MERCURE_PUBLIC_URL` | empty on purpose in `.env`; `https://localhost/.well-known/mercure` in `.env.dev` and `.env.test` | app | no | The hub URL browsers subscribe to. Its origin joins the CSP `connect-src`. Empty adds no source. |
| `MERCURE_TRUSTED_ISSUERS` | `https://localhost` | app (Caddyfile only) | no | Issuer name the hub accepts in tokens. Not in an env file; set it in prod to the public origin. |

## Object storage
| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `OBJECT_STORAGE_ENDPOINT` | empty on purpose in `.env`; `http://minio:9000` in `.env.dev` and `.env.test`; none, required in prod | app | no | The storage address on the app's own network. `/health/ready` opens a TCP connection to it and fails while it is empty. |
| `OBJECT_STORAGE_PUBLIC_URL` | empty on purpose in `.env`; `http://localhost:9000` in `.env.dev` and `.env.test` | app | no | The bucket's own public URL, never a shared host such as `*.amazonaws.com`. Its origin joins the CSP `img-src`. Empty adds no source. |
| `MINIO_ROOT_USER` | `pinround-dev` | minio, minio-init, compose only | no | Dev MinIO admin user. Not in an env file; `compose.yaml` holds the default. |
| `MINIO_ROOT_PASSWORD` | dev placeholder in `compose.yaml` | minio, minio-init, compose only | yes | Dev MinIO admin password. |

## Tracing
Tracing is off while `OTEL_EXPORTER_OTLP_ENDPOINT` is empty. Spans go to the receiver's `/v1/traces` over OTLP/HTTP in batches after the response is sent, each HTTP call is capped at one second, and spans are dropped when it fails.

| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | empty (tracing off) | app, all workers | no | Base URL of the OTLP receiver, for example `http://collector:4318`. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` | app, all workers | no | `http/protobuf` or `http/json`. |
| `OTEL_SERVICE_NAME` | `pinround` | app, all workers | no | Service name on every span. |
| `OTEL_TRACES_SAMPLER_ARG` | `1.0` | app, all workers | no | Share of requests exported, from 0 to 1. Every request starts a new trace. Use `0.1` in prod. |

## Web server and runtime
| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `SERVER_NAME` | `localhost` | app, compose | no | The one host name Caddy serves and the image healthcheck probes. |
| `FRANKENPHP_WORKER_COUNT` | `2` | app (Caddyfile), compose | no | Number of PHP worker processes. |
| `FRANKENPHP_WORKER_CONFIG` | `watch` in `compose.yaml`; empty in prod | app (Caddyfile) | no | In dev, `watch` restarts workers when code changes on the bind mount. |
| `FRANKENPHP_LOOP_MAX` | `500`, fixed in the Caddyfile | app | no | Requests before the Symfony Runtime restarts a worker. Not an env file setting. |
| `CADDY_ADMIN` | `localhost:2019` in dev; `off` in the prod image | app (Caddyfile) | no | Address of Caddy's admin API. |
| `COMPOSER_ALLOW_SUPERUSER` | `0` | app image | no | Keeps Composer's warning about running as root on. The image runs as the non-root `app` user anyway. |

## Docker and compose host settings
These are read by `docker compose` or `make`, not by PHP. The ports, `JAEGER_*`, `MINIO_ROOT_*` and `POSTGRES_*` values go in the git-ignored `.env.local`, which `make` passes to compose with `--env-file`. The `DB_*`, `APP_UID` and `APP_GID` values are make variables: `.env.local` does not set them. Override them on the make command line or in the shell, for example `make db-roles DB_APP_PASSWORD=...`. `COMPOSE_FILE` is read by compose from the shell; make only passes the environment through.

| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `HTTP_PORT` | `80` | compose only | no | Host port for HTTP, bound to `127.0.0.1`. |
| `HTTPS_PORT` | `443` | compose only | no | Host port for HTTPS and HTTP/3, bound to `127.0.0.1`. |
| `MINIO_API_PORT` | `9000` | compose only | no | Host port of the MinIO S3 API. |
| `MINIO_CONSOLE_PORT` | `9001` | compose only | no | Host port of the MinIO web console. |
| `MAILPIT_UI_PORT` | `8025` | compose only | no | Host port of the Mailpit web page. SMTP stays inside the compose network. |
| `JAEGER_UI_PORT` | `16686` | compose only | no | Host port of the Jaeger page (`observability` profile). |
| `JAEGER_OTLP_HTTP_PORT` | `4318` | compose only | no | Host port of the Jaeger OTLP receiver (`observability` profile). |
| `APP_UID` | your user id (`make` sets it from `id -u`); `1000` otherwise | compose and Makefile only | no | Shell or make command line only. User id of the image user, so the bind mount stays writable. `0` falls back to `1000`. |
| `APP_GID` | your group id (`make`); `1000` otherwise | compose and Makefile only | no | Shell or make command line only. Group id of the image user. |
| `COMPOSE_FILE` | none; CI sets `compose.yaml:compose.ci.yaml` | compose only | no | Adds the CI override to the compose files. |
| `DB_ADMIN_USER` | `pinround` | Makefile only | no | Admin account `make db-roles` connects as. Must match `POSTGRES_USER` when that is changed; pass it as `make db-roles DB_ADMIN_USER=...`. |
| `DB_APP_PASSWORD` | dev placeholder | Makefile only | yes | Password `make db-roles` gives `pinround_app`. Must match `DATABASE_URL`. |
| `DB_MIGRATOR_PASSWORD` | dev placeholder | Makefile only | yes | Password for `pinround_migrator`. Must match `MIGRATOR_DATABASE_URL`. |
| `DB_RETENTION_PASSWORD` | dev placeholder | Makefile only | yes | Password for `pinround_retention`. No service uses it yet. |
| `DB_READONLY_PASSWORD` | dev placeholder | Makefile only | yes | Password for `pinround_readonly`. Must match `READONLY_DATABASE_URL`. |
| `DEMO_PASSWORD` | none; no committed file sets it | app (`app:demo:seed`, dev and test only), Makefile | yes | The password of the five demo people (Lena, Omar, Mia, Raj, Sara at `@acme-foods.example`). `app:demo:seed` refuses to run when it is empty or shorter than 12 characters. Set it in `.env.dev.local` (git-ignored, plain `KEY=value`, no quotes, no `$`), which the app container's dotenv and `make` both read, or in your shell. A re-run of the seed never changes an existing person's password. CI generates one per run. |

## Test-only
| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `TEST_STATE_VALKEY_DSN` | `redis://valkey-state:6379` in `.env.test` | test only | no | The state Valkey that `/health/ready` checks in tests, because the test messenger transport is in-memory. The test env also points the state Valkey connection (`STATE_VALKEY_DSN` elsewhere) at it, for the login counters and the session handler test. |
| `E2E_BASE_URL` | `https://localhost` | test only (Playwright) | no | The address the end-to-end tests open. |
| `E2E_PASSWORD` | none; `make e2e` takes `DEMO_PASSWORD`; CI passes the run's `DEMO_PASSWORD` | test only (Playwright) | yes | The password the specs log in as Lena with. It must be the `DEMO_PASSWORD` the seed used. No file in the repo sets it, and the specs fail with a clear message when it is empty. |

`APP_ENV`, `APP_SECRET`, `DEFAULT_URI`, `MERCURE_PUBLIC_URL`, `OBJECT_STORAGE_PUBLIC_URL`, `OBJECT_STORAGE_ENDPOINT`, `REDIS_CACHE_DSN`, the database URLs, `MAILER_DSN` and `MESSENGER_TRANSPORT_DSN` also have test values in `.env.test`. They are listed in the sections above.

## CI only
| Variable | Default | Read by | Secret | What it does |
|---|---|---|---|---|
| `COMPOSER_NO_INTERACTION` | `1` | CI only | no | Stops Composer from asking questions. |
| `PGPASSWORD` | `pinround` (CI placeholder) | CI only | no | Admin password for the `psql` steps. |
| `CI` | set by GitHub | CI only | no | Tells Playwright to skip the pixel compares and use the GitHub reporter. |

The integration job sets real environment variables that beat `.env.test`: the database URLs (CI placeholder passwords), `REDIS_CACHE_DSN`, `TEST_STATE_VALKEY_DSN`, `OBJECT_STORAGE_ENDPOINT` (a stand-in listener), `MESSENGER_TRANSPORT_DSN`, `MAILER_DSN` and `DEFAULT_URI`. The Postgres service container sets `POSTGRES_USER`, `POSTGRES_PASSWORD` and `POSTGRES_DB`.
