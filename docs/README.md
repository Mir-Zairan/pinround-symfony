# Docs map

Where every document is, what it holds, and the order to read it in.

## Index
- [Reading order](#reading-order) — first day, before a phase, before a decision
- [00 Product](#00-product) — vision, words, people, UX rules
- [01 Requirements](#01-requirements) — what the system must do and how well
- [02 Architecture](#02-architecture) — how it is built
- [Operations](#operations) — the configuration reference
- [Guides](#guides) — how-tos for people who use pinround
- [03 Decisions (ADRs)](#03-decisions-adrs) — why it is built that way
- [04 Prototype](#04-prototype) — the clickable prototype, screen by screen
- [05 Plan](#05-plan) — phases and tasks, one task per Claude Code session
- [Conventions](#conventions) — IDs, the 100-line index rule, examples

## Reading order
**First day:** [vision](00-product/vision.md) → [glossary](00-product/glossary.md) → [prototype guide](04-prototype/prototype-guide.md) → [architecture overview](02-architecture/architecture-overview.md) → [plan](05-plan/README.md).

**Before a phase task:** the phase file's "Before you start" list. It names the prototype screens, requirements, ADRs and architecture docs for that task.

**Before a new decision:** [ADR index](03-adr/README.md) and [trade-offs](02-architecture/trade-offs.md).

## 00 Product
| File | Holds |
|---|---|
| [vision.md](00-product/vision.md) | The problem, the idea, success measures, non-goals |
| [glossary.md](00-product/glossary.md) | Every product word and what it means |
| [personas-and-roles.md](00-product/personas-and-roles.md) | People, roles, the permission matrix |
| [ux-principles.md](00-product/ux-principles.md) | UX rules from how people think, design tokens, copy rules |

## 01 Requirements
| File | Holds |
|---|---|
| [functional-requirements.md](01-requirements/functional-requirements.md) | FR-* — every feature with acceptance criteria |
| [non-functional-requirements.md](01-requirements/non-functional-requirements.md) | NFR-* — security, performance, scale, usability targets and how they are checked |
| [constraints.md](01-requirements/constraints.md) | C-* — fixed limits (deadline, team, budget, data) and how we respond |

## 02 Architecture
| File | Holds |
|---|---|
| [architecture-overview.md](02-architecture/architecture-overview.md) | The big picture, C4 diagrams, request flow, quality scenarios |
| [modules.md](02-architecture/modules.md) | Each module: what it owns, its API, its events |
| [data-model.md](02-architecture/data-model.md) | Tables, indexes, partitions, read models |
| [workflow-engine.md](02-architecture/workflow-engine.md) | Steps, groups, verbs, rules, whose turn, Now/Next |
| [frontend.md](02-architecture/frontend.md) | Symfony UX, components, routes, design tokens |
| [realtime-and-notifications.md](02-architecture/realtime-and-notifications.md) | Mercure, pings, bell, digests |
| [security.md](02-architecture/security.md) | Threat model, controls, tenant isolation |
| [performance-and-scale.md](02-architecture/performance-and-scale.md) | Capacity math, budgets, hot paths, scaling steps |
| [ai-architecture.md](02-architecture/ai-architecture.md) | Symfony AI gateway, providers, laptop AI, prompts |
| [media-pipeline.md](02-architecture/media-pipeline.md) | Screenshot upload, processing, serving |
| [integrations.md](02-architecture/integrations.md) | Jira, SSO, email, browser plugin, Claude Code |
| [operations.md](02-architecture/operations.md) | Deploy, backups, monitoring, runbooks |
| [testing-strategy.md](02-architecture/testing-strategy.md) | Test types, tools, CI gates |
| [trade-offs.md](02-architecture/trade-offs.md) | The big tensions, what we chose, risks |

## Operations
| File | Holds |
|---|---|
| [config.md](operations/config.md) | Every environment variable: default, who reads it, secret or not, prod rules |

## Guides
| File | Holds |
|---|---|
| [claude-code.md](guides/claude-code.md) | Connect Claude Code to pinround: token, `claude mcp add`, the tools, uploads, safety |
| [reporting-with-claude-code.md](guides/reporting-with-claude-code.md) | Report while Claude Code checks: the `report_while_investigating` prompt, token scopes, the evidence log, the choices at the end, Send; the [scenario format](guides/reporting-with-claude-code.md#scenarios) and `save_scenarios` |
| [demo.md](guides/demo.md) | `make demo`: a first look from ready-made images in about 4 minutes, when it builds instead, and how to publish new images |
| [browser-extension.md](guides/browser-extension.md) | The Chrome extension: install, connect, capture a page, [notebooks](guides/browser-extension.md#notebooks), [asking Claude Code about one](guides/browser-extension.md#ask-claude-code-about-a-notebook), [playing scenarios](guides/browser-extension.md#play-scenarios), allowed hosts and [safety](guides/browser-extension.md#safety) |

## 03 Decisions (ADRs)
[03-adr/README.md](03-adr/README.md) lists all 30 decisions with a one-line summary each.

## 04 Prototype
[04-prototype/prototype-guide.md](04-prototype/prototype-guide.md) explains how to open the prototype and links one file per screen in [04-prototype/screens](04-prototype/screens).

## 05 Plan
[05-plan/README.md](05-plan/README.md) lists phases P00–P13, their order, the hackathon slice, and how to run one task per session.

## Conventions
- **IDs:** requirements `FR-<AREA>-NN`, `NFR-<CAT>-NN`, constraints `C-NN`, decisions `ADR-NNNN`, tasks `PNN.Tn`.
- **The 100-line rule:** any Markdown file longer than 100 lines starts with `## Index`. Claude Code often previews only the first ~100 lines of a file, so the index shows what lies further down.
- **Examples:** only neutral examples (Acme Foods, project SHOP, Lena, Omar, Mia, Raj, Sara). Never real company data.
- **Diagrams:** Mermaid, so GitHub renders them and they stay editable.
