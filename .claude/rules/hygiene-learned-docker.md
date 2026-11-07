---
paths:
  - "Dockerfile"
  - "docker/**"
  - "compose*.yaml"
  - ".dockerignore"
  - ".gitignore"
  - ".env*"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- `.env` holds empty values only; dev placeholders go in `.env.dev`, real values come from the environment. <!-- class: secrets, source: 20261106-f185 -->
- Every path listed in `.gitignore` (secret files, `node_modules`, test output, `assets/vendor`) is also listed in `.dockerignore`, because the image is built from the working folder, not from git. <!-- class: secrets, source: 20261106-f184, 20261107-7d60, 20261107-8bd7 -->
- Pin base images by `@sha256` digest and source builds by commit SHA, never by a tag that can move. <!-- class: dependency, source: 20261106-1ec8 -->
- Images we build locally get a name with no registry namespace and `pull_policy: never`, so Compose never downloads them. <!-- class: dependency, source: 20261106-3817 -->
- Define a container healthcheck once (Dockerfile `HEALTHCHECK`); compose may only override `start_period`, with a comment why. <!-- class: duplication, source: 20261106-7563, 20261106-4850 -->
