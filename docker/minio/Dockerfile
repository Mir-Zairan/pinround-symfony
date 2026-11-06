# syntax=docker/dockerfile:1

# Upstream stopped publishing MinIO images, so we build the server and client from pinned source (ADR-0031).
# Update a pin by changing the tag and its commit together; the build fetches the commit by SHA.
ARG MINIO_RELEASE=RELEASE.2025-10-15T17-29-55Z
ARG MINIO_COMMIT=9e49d5e7a648f00e26f2246f4dc28e6b07f8c84a
ARG MC_RELEASE=RELEASE.2025-08-13T08-35-41Z
ARG MC_COMMIT=7394ce0dd2a80935aded936b09fa12cbb3cb8096

FROM golang:1.25-alpine3.22@sha256:65b4400aee0927412e9ed791a11893273a49d55df24841f7599660fb80dae464 AS build
ARG MINIO_COMMIT
ARG MC_COMMIT
RUN apk add --no-cache git
ENV CGO_ENABLED=0
RUN git init --quiet /src/minio \
    && cd /src/minio \
    && git fetch --quiet --depth 1 https://github.com/minio/minio.git ${MINIO_COMMIT} \
    && git checkout --quiet FETCH_HEAD \
    && go build -trimpath -o /out/minio .
RUN git init --quiet /src/mc \
    && cd /src/mc \
    && git fetch --quiet --depth 1 https://github.com/minio/mc.git ${MC_COMMIT} \
    && git checkout --quiet FETCH_HEAD \
    && go build -trimpath -o /out/mc .

FROM alpine:3.22@sha256:bfe7ceb9f09ec0566c48fb93356e45a47a00be5a1dd14fa5b909e7709c66b034
ARG MINIO_RELEASE
ARG MC_RELEASE
LABEL org.opencontainers.image.version=${MINIO_RELEASE}
LABEL dev.pinround.mc-release=${MC_RELEASE}
RUN apk add --no-cache ca-certificates curl \
    && addgroup -S minio \
    && adduser -S -G minio -h /home/minio minio \
    && mkdir /data \
    && chown minio:minio /data
COPY --from=build /out/minio /out/mc /usr/local/bin/
USER minio
VOLUME /data
EXPOSE 9000 9001
ENTRYPOINT ["minio"]
CMD ["server", "/data", "--console-address", ":9001"]
