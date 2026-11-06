# syntax=docker/dockerfile:1

# Upstream stopped publishing MinIO images (Docker Hub and Quay), so we build the server and the client from pinned source tags.
ARG MINIO_RELEASE=RELEASE.2025-10-15T17-29-55Z
ARG MC_RELEASE=RELEASE.2025-08-13T08-35-41Z

FROM golang:1.25-alpine3.22 AS build
ARG MINIO_RELEASE
ARG MC_RELEASE
RUN apk add --no-cache git
ENV CGO_ENABLED=0
RUN git clone --quiet --depth 1 --branch ${MINIO_RELEASE} https://github.com/minio/minio.git /src/minio \
    && cd /src/minio \
    && go build -trimpath -o /out/minio .
RUN git clone --quiet --depth 1 --branch ${MC_RELEASE} https://github.com/minio/mc.git /src/mc \
    && cd /src/mc \
    && go build -trimpath -o /out/mc .

FROM alpine:3.22
ARG MINIO_RELEASE
LABEL org.opencontainers.image.version=${MINIO_RELEASE}
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
