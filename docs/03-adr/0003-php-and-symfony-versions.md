# ADR-0003 — Use PHP 8.4+ and Symfony 8.1, then move to 8.2 and 8.4 LTS

We pick the PHP and Symfony versions and say when we upgrade.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

The hackathon asks for Symfony AI and the MCP SDK, which move fast and follow new
Symfony releases. At the same time, Sara (admin at a customer) wants a product that
gets security fixes for years. Today (2026-11-06): Symfony 8.1 is the current stable
release (PHP ≥ 8.4, support until January 2027). Symfony 8.2 is due at the end of
November 2026. Symfony 7.4 is the current LTS (bug fixes until November 2028).
The next LTS, 8.4, is due in November 2027.

Which versions do we start on, and how do we stay supported?

## Decision drivers

- Symfony AI 1.0.0-BETA1 (verified 2026-11-06; check again at implementation) and `symfony/mcp-bundle` target current Symfony versions (C-02 hackathon).
- Security support must never lapse (NFR-SEC).
- Small team: upgrades must be small and frequent, not big and rare.
- PHP features: property hooks, asymmetric visibility, readonly classes (PHP 8.4).

## Considered options

1. Symfony 8.1 now, 8.2 on release, then each minor until 8.4 LTS.
2. Symfony 7.4 LTS and stay until 2028.
3. Symfony 8.1 and stay on it.

## Decision outcome

Chosen option: **Symfony 8.1 now, upgrade to each minor release, settle on 8.4 LTS
in November 2027**, with **PHP 8.4 as the minimum and PHP 8.5 in the Docker image**.

### Consequences

- Good: we get the newest Symfony AI and MCP features for the hackathon.
- Good: minor upgrades are small (Symfony keeps backward compatibility within 8.x).
- Bad: about 4 minor upgrades in one year (8.2, 8.3, 8.4); each needs a short session.
- Bad: some third-party bundles may lag behind a new minor for a few weeks.
- Neutral: after 8.4 LTS we may stay on LTS for self-hosted customers.

## Confirmation

- `composer.json` sets `"php": ">=8.4"` and `symfony/*` to `8.1.*` (then `8.2.*` …).
- CI runs on PHP 8.4 and 8.5.
- A scheduled CI job each week runs `composer outdated symfony/*` and opens an issue when a new minor or patch exists.
- `composer audit` runs on every pull request.

## Pros and cons of the options

### 8.1 now, then each minor to 8.4 LTS

- Good, because we stay current with Symfony AI and MCP.
- Bad, because there are more upgrade sessions.

### 7.4 LTS

- Good, because there are long support dates and fewer upgrades.
- Bad, because Symfony AI and new bundles focus on 8.x; we would miss features during the hackathon.
- Bad, because we would face one big 7.4 → 8.4 jump later.

### 8.1 and stay

- Good, because there is no upgrade work now.
- Bad, because support ends in January 2027. Not acceptable.

## More information

- Releases: https://symfony.com/releases
- Revisit when: Symfony 8.4 LTS ships (November 2027), or a Symfony AI release needs a newer PHP.
