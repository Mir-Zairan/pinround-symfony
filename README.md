# pinround

Screenshot-first review and ticketing. Built with Symfony 8, Symfony AI and the official PHP MCP SDK.

Most ticket tools tell you what *status* a ticket has. pinround tells you **what to do next**.
Problems are shown on screenshots with numbered pins. Work moves in *rounds*: the developer fixes, the tester checks each pin, and everyone sees "Now: …" and "Next: …" in plain words.

> Status: architecture and plan are ready. No application code yet. Start with [docs/README.md](docs/README.md).

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
