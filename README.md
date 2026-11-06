# pinround

Screenshot-first review and ticketing. Built with Symfony 8, Symfony AI and the official PHP MCP SDK.

Most ticket tools tell you what *status* a ticket has. pinround tells you **what to do next**.
Problems are shown on screenshots with numbered pins. Work moves in *rounds*: the developer fixes, the tester checks each pin, and everyone sees "Now: …" and "Next: …" in plain words.

> Status: the P00 skeleton and Docker stack run locally (see "Run it locally"). Application features come phase by phase; see [phase 0](docs/05-plan/phase-00-foundation.md) and [docs/README.md](docs/README.md).

## Run it locally

You need Docker with Compose v2, and `make`. You do not need PHP on your machine.

1. Run `make up`. The first run builds MinIO from source and takes about 5-6 minutes. Later runs start in seconds.
2. Open https://localhost and accept the local certificate that Caddy makes.
3. Mail the app sends shows up in Mailpit at http://localhost:8025.
4. The MinIO console is at http://localhost:9001.

On Linux, `make` passes your user and group id to the build. If you run plain `docker compose up` instead, set `APP_UID` and `APP_GID` in `.env.local` (run `id -u` and `id -g`).

If a port is taken, put a new one in `.env.local`, for example `HTTP_PORT=8081`. The other settings are `HTTPS_PORT`, `MAILPIT_UI_PORT`, `MINIO_API_PORT` and `MINIO_CONSOLE_PORT`.

Run `make help` to see every command.

## Why it exists

- A lead should not lose a morning finding out what waits on them. Home shows the top 5, with the reason for each.
- A tester should not write a novel to explain a bug. They drop pins on a screenshot.
- A developer working with Claude Code should be able to say "read my pinround notes and fix what is wrong", and it works.
- A manager should compare teams without learning each team's process. Every team maps to the same five groups.

## What is inside

| Area | Where |
|---|---|
| Product vision, personas, UX rules | [docs/00-product](docs/00-product) |
| Requirements (functional, non-functional, constraints) | [docs/01-requirements](docs/01-requirements) |
| Architecture (modules, data, security, performance, AI) | [docs/02-architecture](docs/02-architecture) |
| Architecture decision records | [docs/03-adr](docs/03-adr) |
| The clickable prototype, screen by screen | [docs/04-prototype](docs/04-prototype) |
| Phase-by-phase build plan | [docs/05-plan](docs/05-plan) |

## Tech at a glance

PHP 8.4+ · Symfony 8.1 (8.2 on release) · PostgreSQL 18 · Valkey · S3-compatible storage · FrankenPHP with Mercure · Symfony UX (Turbo, Stimulus, Live Components) · Symfony AI (Hugging Face, Anthropic, Ollama) · MCP server for Claude Code.

## Working on this repo with Claude Code

Read [CLAUDE.md](CLAUDE.md) first. Every build session starts from one phase file in [docs/05-plan](docs/05-plan).

## Data rule

This repository must never contain real customer data, real ticket content or real people's data from any company system. Use the neutral examples from the docs (Acme Foods, project SHOP, Lena, Omar, Mia, Raj, Sara).
