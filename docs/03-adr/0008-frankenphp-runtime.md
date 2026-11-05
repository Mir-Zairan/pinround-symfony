# ADR-0008 — Run pinround on FrankenPHP in worker mode

The app server is FrankenPHP (built on Caddy) in worker mode, with its built-in Mercure hub.

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — why the app server matters.
- [Decision drivers](#decision-drivers) — latency, simplicity, real-time.
- [Considered options](#considered-options) — nginx + PHP-FPM, FrankenPHP classic, FrankenPHP worker.
- [Decision outcome](#decision-outcome) — setup and the worker-mode rules.
- [Confirmation](#confirmation) — tests for state leaks and memory.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

Lena opens Home 40 times a day. Each time, classic PHP boots the whole Symfony kernel,
loads the container and routes, then throws everything away. At 500 requests per second
at peak, that boot time is wasted CPU and adds latency. We also need a Mercure hub for
live updates, and HTTPS with HTTP/2 and HTTP/3.

Which PHP runtime gives low latency, simple operations, and real-time, in one image?

## Decision drivers

- p95 budgets: Home ≤ 250 ms, list ≤ 200 ms (NFR-PERF).
- One Docker image for SaaS and self-host (C-self-host, [ADR-0030](0030-deployment-model.md)).
- Built-in TLS, HTTP/2/3, Brotli/zstd compression.
- A Mercure hub without another service ([ADR-0023](0023-realtime-mercure.md)).

## Considered options

1. nginx + PHP-FPM (classic).
2. FrankenPHP in classic mode (one boot per request).
3. FrankenPHP in worker mode (the kernel stays in memory between requests).

## Decision outcome

Chosen option: **FrankenPHP in worker mode**, through the Symfony Runtime component,
with the **built-in Mercure hub** enabled in the Caddyfile.

Worker-mode rules (they prevent data from one request leaking into the next):

1. **No static or global state** in our code. Caches inside a service must be cleared on reset.
2. Every service that keeps per-request data implements
   `Symfony\Contracts\Service\ResetInterface` (or is tagged `kernel.reset`).
   Symfony calls reset between requests. Examples: `TenantContext`, the current-user holder,
   request-scoped memo caches.
3. The Doctrine `EntityManager` is cleared between requests (Symfony's reset does this;
   verify on upgrade). Never keep entities in a service property.
4. Workers restart after a fixed number of requests (`max_requests`, start with 500)
   to cap memory growth from leaks in libraries.
5. Long tasks never run in a web request; they go to Messenger ([ADR-0009](0009-async-messaging-and-outbox.md)).
6. Messenger consumers run as separate processes (`messenger:consume`) in the same image,
   with `--memory-limit` and `--time-limit`.

### Consequences

- Good: the kernel boots once per worker, so we expect lower latency and CPU per request (to be measured in P12).
- Good: one image includes the web server, TLS, compression and the Mercure hub.
- Bad: state leaks between requests become possible; rules and tests are needed.
- Bad: some libraries assume one request per process; each new dependency must be checked.
- Neutral: for local development, worker mode can be switched off for easier debugging.

## Confirmation

- **State-leak test** (functional, runs in CI in worker mode): two requests in a row as users
  of different orgs; the second must never see the first's tenant, user or cached data.
- PHPStan rule (custom, P00): forbids `static` properties in `src/` except constants
  and explicitly allowed classes.
- A container compile pass (P00) lists services with mutable properties that are not
  tagged `kernel.reset`; CI fails on new entries.
- The memory of a worker after 500 requests stays under a set limit in the P12 soak test.

## Pros and cons of the options

### nginx + PHP-FPM

- Good, because it is well known and has no state-leak risk.
- Bad, because the kernel boots on every request; it needs nginx plus FPM plus a separate Mercure hub.

### FrankenPHP classic mode

- Good, because it is one binary with TLS and Mercure, and has no state-leak risk.
- Bad, because the kernel still boots on every request.

### FrankenPHP worker mode

- Good, because it has the lowest latency and is one binary with Mercure.
- Bad, because it needs reset discipline and leak tests.

## More information

- FrankenPHP: https://frankenphp.dev — check worker and Mercure options at implementation.
- Revisit when: a state-leak bug reaches production, or worker memory grows more than 50 MB per 500 requests
  after fixes. Then fall back to classic mode (same image, config change).
