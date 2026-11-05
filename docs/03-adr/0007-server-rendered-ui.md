# ADR-0007 — Server-rendered UI with Symfony UX, no SPA

The web UI is rendered by Twig on the server and made interactive with Symfony UX (Turbo, Stimulus, Live Components).

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — what the UI must do.
- [Decision drivers](#decision-drivers) — team, security, speed, accessibility.
- [Considered options](#considered-options) — SPA, Inertia-style hybrid, Symfony UX.
- [Decision outcome](#decision-outcome) — the stack, design tokens, rules.
- [Confirmation](#confirmation) — budgets and checks.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

The prototype is very interactive. Lena clicks "Start reviews" and walks ticket after
ticket. Mia clicks on a screenshot to drop pin 3 and types a note. Omar presses
"Send to code review" and the "Now:" line changes at once. Raj opens Home on a slow
laptop. The team is three people, all strong in PHP and Symfony.

Which UI architecture gives this feel, stays secure and fast, and is small enough
for three people to own?

## Decision drivers

- Team of 3 PHP developers, hackathon deadline (C-01, C-02).
- Security: fewer public JSON endpoints means a smaller attack surface; authorization stays on the server (NFR-SEC).
- Performance: LCP ≤ 2.0 s, INP ≤ 200 ms, JS ≤ 120 KB gzip per page (NFR-PERF).
- Accessibility: WCAG 2.2 AA, works at 360 px width (NFR-A11Y, NFR-USE).
- Match the prototype look: M3 colours from seed `#2A6F65`, Atkinson Hyperlegible Next.

## Considered options

1. React or Vue single-page app with a JSON API.
2. A hybrid (server routes with a JS framework per page, Inertia-style).
3. Twig + Symfony UX (Turbo, Stimulus, Live Components, Twig Components) + AssetMapper.

## Decision outcome

Chosen option: **Twig + Symfony UX + AssetMapper**, because one language and one
codebase can deliver the prototype's interactions, with authorization only on the server.

The stack:

- **Twig Components** for the design system: `Button`, `Chip`, `Tag`, `Avatar`, `AvatarStack`,
  `StatusPill`, `NowNext`, `Bar`, `Pin`, `Dialog`, `Sheet`, `Toast`, `Card`.
- **Live Components** for stateful widgets: person picker with search, comment composer
  (comment/question switch), focus chips, intake form with live duplicate suggestions.
- **Turbo Drive/Frames** for page navigation and partial updates (ticket queue Previous/Next,
  filters). **Turbo Streams over Mercure** for live updates ([ADR-0023](0023-realtime-mercure.md)).
- **Stimulus controllers** for client-only behaviour: pin placing on screenshots,
  presigned upload with progress ([ADR-0011](0011-screenshot-storage-and-processing.md)),
  local AI with Ollama ([ADR-0019](0019-browser-local-ai.md)), keyboard shortcuts.
- **AssetMapper + importmap**: no Node build in production. Fonts self-hosted.
- **Design tokens**: CSS custom properties generated once from the M3 seed `#2A6F65`
  (light and dark), plus fixed colours for the five groups ([ADR-0014](0014-status-model-five-groups.md)).
  Tokens live in one file; components never hard-code colours.

Rules:

- Every interactive control is a real `<button>`, `<a>` or form field with a visible focus style.
- Every mutating request is a form POST or Live action with CSRF protection.
- No inline `<script>`. A CSP with nonces is set ([ADR-0027](0027-secrets-and-encryption.md)).
- Pages render complete HTML first. JavaScript adds to it.

### Consequences

- Good: one codebase; templates reuse voters (`is_granted`) directly.
- Good: small JS payloads; fast first paint; good for slow laptops.
- Good: no separate public API for the UI, so a smaller attack surface.
- Bad: very rich canvas features (pin drag, zoom) need careful Stimulus code.
- Bad: Live Components add server round trips; widgets must be designed to keep them small.
- Neutral: a JSON API still exists for the browser plugin and MCP ([ADR-0021](0021-mcp-server.md)), but it is separate and scoped.

## Confirmation

- Lighthouse CI on key pages (Home, ticket list, ticket page) fails if LCP > 2.0 s or JS > 120 KB gzip.
- axe accessibility checks in Playwright e2e fail on WCAG A/AA violations.
- A CSP report-only check in e2e fails on any inline script.
- Code review: no colour literals in templates (a lint script scans for `#` hex outside the token file).

## Pros and cons of the options

### React/Vue SPA + JSON API

- Good, because rich client interactions are easy.
- Bad, because there are two codebases and a public API for everything, with authorization repeated.
- Bad, because there are bigger bundles and slower first load on slow laptops.

### Hybrid (Inertia-style)

- Good, because server routing comes with client components.
- Bad, because a Node build is still needed; it adds a second UI language for a team of three.

### Twig + Symfony UX

- Good, because it is one language, server authorization, small JS, and fits Symfony.
- Bad, because it is less suited for heavy offline or canvas apps.

## More information

- Frontend details: [frontend.md](../02-architecture/frontend.md). UX rules: [ux-principles.md](../00-product/ux-principles.md).
- Revisit when: INP p75 > 200 ms on the ticket page after tuning, or a feature needs offline use.
