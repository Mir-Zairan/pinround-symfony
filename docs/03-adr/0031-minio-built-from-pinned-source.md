# ADR-0031 — Build MinIO from pinned source

MinIO stays our object store ([ADR-0011](0011-screenshot-storage-and-processing.md)), but we build its server and client from pinned upstream source in `docker/minio`, because upstream no longer publishes images.

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — why a pull no longer works.
- [Decision drivers](#decision-drivers) — supply chain, repeatability, effort.
- [Considered options](#considered-options) — mirror, other store, build from source.
- [Decision outcome](#decision-outcome) — what we build, who updates the pins.
- [Confirmation](#confirmation) — checks.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

Sara sets up pinround for Acme Foods on a fresh server and runs `docker compose up`.
The stack needs the private screenshot bucket from [ADR-0011](0011-screenshot-storage-and-processing.md),
but the MinIO and `mc` images are gone from Docker Hub and Quay, so the pull fails.
How do we keep MinIO in the stack without trusting an image nobody at pinround built?

## Decision drivers

- A repeatable stack on a clean clone ([ADR-0030](0030-deployment-model.md)).
- No unverified third-party image that receives the MinIO root credentials.
- Small upkeep: one place to bump a version.

## Considered options

1. Pull a community mirror image.
2. Switch to another S3-compatible store.
3. Build `minio` and `mc` from pinned upstream source.

## Decision outcome

Chosen option: **build from pinned source**, because it keeps the ADR-0011 choice and removes the unverified image.

- `docker/minio/Dockerfile` builds `minio` and `mc` in a Go stage and copies them into a small Alpine stage that runs as a non-root user.
- Each tool is pinned to a release tag **and** its commit SHA; the build fetches the commit, so a moved tag cannot change the result. Both base images are pinned by digest.
- The image has a local-only name (`pinround-minio:dev`) and compose uses `pull_policy: never` (a missing image is still built), so it is never pulled from a registry.
- Self-host production gets MinIO from the image that CI builds from the same `docker/minio` Dockerfile, scans and signs ([ADR-0030](0030-deployment-model.md)); the pipeline lands in P12. Dev keeps the local build.
- The pins (`MINIO_RELEASE`, `MINIO_COMMIT`, `MC_RELEASE`, `MC_COMMIT`) are changed by the maintainer who owns the Docker stack, in one pull request that also bumps the base-image digests when needed. They are checked at least once per quarter and right away for a security advisory.

### Consequences

- Good: no registry trust for MinIO; the build is repeatable.
- Bad: the first build compiles Go and takes about 5 minutes; we own the updates.
- Good: production runs a scanned, signed image, not a build on the server.
- Neutral: SaaS uses a managed S3 service and does not run this image.

## Confirmation

`make up` builds the image on a clean clone and `minio-init` creates the private bucket; the anonymous-read check returns 403.
`tests/shell/compose-config-check.sh` fails unless:

- `minio`, `minio-init`, `app`, `worker` and `scheduler` have a `pull_policy` that never pulls (`never` or `build`);
- `docker/minio/Dockerfile` has the `*_COMMIT` build args and base images pinned with `@sha256:`;
- the compose file is valid, has every service, and each long-running service has a healthcheck.

## Pros and cons of the options

### Community mirror

- Good, because there is no build.
- Bad, because we trust an unknown publisher with the root credentials.

### Another S3 store

- Good, because it may have maintained images.
- Bad, because it reopens [ADR-0011](0011-screenshot-storage-and-processing.md) and needs a new compatibility check.

### Build from pinned source

- Good, because we control exactly what runs.
- Bad, because of build time and update work.

## More information

- Related: [ADR-0011](0011-screenshot-storage-and-processing.md), [ADR-0030](0030-deployment-model.md).
- Revisit when: upstream publishes signed images again, or a build fails twice in a row because of upstream changes.
