# ADR-0046 — Notebooks hold pins for questions, and the extension plays scenarios that Claude Code writes

A person pins screens to ask Claude Code about them. Claude Code can answer with scenarios: short, checked steps that
the browser extension plays live on the person's app, one by one.

## Index

- [Status](#status) — Accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — Lena wants to see every warning of a page.
- [Decision drivers](#decision-drivers) — privacy, no code from AI runs in the page, a person starts each run.
- [Considered options](#considered-options) — notebook + step list, generated test scripts, Claude Code drives alone.
- [Decision outcome](#decision-outcome) — the notebook, the scenario format, the player, the limits.
- [Confirmation](#confirmation) — privacy, format, player and safety tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs, requirements and revisit triggers.

## Status

Accepted

- Date: 2026-11-19
- Deciders: Zairan Mir (lead, product owner)

It supersedes part of the scope and tool lists of [ADR-0021](0021-mcp-server.md), as already changed by
[ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md): it adds the scope `notebooks:write` and the tools
`list_my_notebooks`, `get_notebook` and `save_scenarios`.

## Context and problem statement

Lena, a lead at Acme Foods, takes over the order page. She does not know when its warnings show. She pins three
spots on the running app ("why is this grey?", "when does this banner show?") and wants her Claude Code to explain.
Then she asks: "Show me every warning on this page." Claude Code finds six in the code. Lena wants to see each one
happen live on her app, one after the other, not read about them.

These questions are not tickets. Nobody else needs to see them.

Where do pins for a question live, and how can the extension show cases happening live without running code that an
AI wrote inside the person's browser?

## Decision drivers

- A question is private. It is not a ticket and not a round
  ([ADR-0017](0017-private-draft-rounds.md) privacy rules).
- Steps an AI wrote are untrusted ([ADR-0020](0020-ai-safety-and-prompt-injection.md)). The extension never evaluates
  code from them.
- A person starts each run. Playing a scenario changes data in the person's app.
- Scenarios run only on hosts the person allowed, never on pinround itself and never on production by accident.
- The same pin model and the same upload path as everywhere else (FR-RND-02,
  [ADR-0011](0011-screenshot-storage-and-processing.md)).

## Considered options

1. **A notebook plus a fixed list of step types**, played by the extension.
2. **Generated test scripts** (for example Playwright files) that Claude Code writes and the person runs.
3. **Claude Code drives the browser alone** and the person only watches.

## Decision outcome

Chosen option: **1, a notebook plus a fixed list of step types**, because the person sees each case happen in their
own browser, step by step, and the extension accepts only data, never code.

- **Notebook.** A notebook is a private space owned by one person. It holds:
  - a name;
  - an optional project;
  - up to 10 screenshots with titles, summaries and pins;
  - up to 20 scenarios.
  Only its owner can see it: not admins, not the team. Everyone else gets "not found". It is not linked to a ticket.
- **Owner: Review.** It holds screenshots and pins, which Review owns. New tenant table `rvw_notebook`: id,
  organization_id, owner_id, project_id NULL, name, screenshots jsonb, scenarios jsonb, created_at, updated_at.
  `organization_id` comes first in the primary key and in every index, and row-level security is on. It follows the
  ADR-0040 report-draft pattern of one jsonb array. The upload purpose `screenshot` is reused with target kind
  `notebook`.
- **Asking.** The extension's "Pin this page" (ADR-0045) has a third target, "Notebook". The person asks Claude Code,
  "explain my notebook 'Order page'". Claude Code calls `get_notebook` (scope `read`) and gets the pictures (signed
  URLs) and pins. Titles, summaries and pin notes come back inside delimiters, as untrusted text. The answer stays in
  Claude Code. Pinround does not store answers.
- **Scenario format.** A scenario is JSON with:
  - `title` (≤ 120 characters);
  - `why`: what in the code makes it happen (≤ 600);
  - `setup`: what data it needs, in plain words (≤ 600);
  - `start_url`;
  - `steps` (at most 30).
  Each step has one of these types: `go_to`, `click`, `type`, `select`, `wait_for`, `expect_text`,
  `expect_visible`, `pause` and `capture`. A step finds its element by accessible role and name, by visible text or
  by label. It never uses a CSS or XPath string. Each step also has a `say` line (≤ 200), which the player shows
  while the step runs. Text values are at most 1,000 characters. Anything else is refused with a plain error.
- **Writing scenarios.** Claude Code reads the code, finds the cases and calls `save_scenarios {notebook, scenarios}`
  (new scope `notebooks:write`). This replaces the notebook's scenarios. The data a scenario needs is prepared by
  Claude Code in the person's local app before the person plays it, for example with a seed command. The scenario's
  `setup` text says what it expects, and a `pause` step can ask the person to wait for it.
- **Player.** In the extension, "Scenarios" lists the notebook's scenarios. "Play" runs one, step by step:
  - each step is highlighted, with its `say` line;
  - "Next" and "Back" move between scenarios;
  - "Step" walks one step at a time.
  An `expect_*` step that fails stops the run and shows what was expected and what was found. A `capture` step adds
  a screenshot to the notebook, so a case can turn into a report later through the ADR-0045 targets.
- **Where it plays.** Only on hosts the person added to the extension's allowed list (`http://localhost:*` is
  suggested, nothing is allowed by default). Never on the pinround host. The player asks before the first run on each
  host: "This scenario clicks and types on <host>. It can change data there."
- **No code.** The player has one handler per step type. It never calls `eval`, `new Function`, script injection
  with AI text, or `javascript:` URLs. `go_to` accepts only URLs on the allowed host. A `type` step into a password
  field is refused.

### Consequences

- Good: a person can see every case of a rule happen on their own app, in a few clicks.
- Good: a case that turns out wrong becomes a report through the same capture path.
- Good: the player runs only data, so a prompt injection in a ticket cannot make the extension run code.
- Bad: a fixed step list cannot show everything. Hover menus, drag and drop and file pickers are left out at first.
- Bad: setup data depends on the person's app. Pinround cannot prepare it, so Claude Code must.
- Neutral: one more private object, notebooks, with its own retention rule (set in P11).

## Confirmation

- Visibility and isolation tests: Lena's notebook is invisible to Omar, Sara and another organization (HTML, queries,
  direct URLs, `get_notebook`). `rvw_notebook` has RLS on (`CatalogTest`).
- MCP contract tests: `save_scenarios` without `notebooks:write` is an error and changes nothing. An unknown step type,
  a CSS selector, 31 steps, 21 scenarios or an over-long text are refused, and nothing is saved.
- Extension unit tests: every step type maps to its handler. An unknown type stops the run. `go_to` to another host
  is refused. `type` into a password field is refused.
- Extension e2e: the player runs a three-scenario fixture against a test page, stops on a failed `expect_text` and
  shows both texts, and "Next" goes to the next scenario.
- A search of the extension source for `eval(` and `new Function` finds nothing (a CI step).

## Pros and cons of the options

### Option 1, notebook and fixed step types

- Good, because the person watches each case live in their own browser.
- Good, because the extension only interprets data, so it is easy to make safe.
- Bad, because the step list must grow when people need new kinds of steps.

### Option 2, generated test scripts

- Good, because test tools can do everything.
- Bad, because the person must run code that an AI wrote, and they do not see it happen step by step in their own
  browser.

### Option 3, Claude Code drives the browser alone

- Good, because it needs no extension.
- Bad, because the person cannot go back and forth between cases, and the run is not in their own browser session.

## More information

- Related: [ADR-0011](0011-screenshot-storage-and-processing.md), [ADR-0017](0017-private-draft-rounds.md),
  [ADR-0020](0020-ai-safety-and-prompt-injection.md), [ADR-0021](0021-mcp-server.md),
  [ADR-0040](0040-report-drafts-live-in-review.md), [ADR-0045](0045-evidence-is-captured-where-the-person-works.md).
- Requirements: FR-EXP-01 to FR-EXP-06.
- Plan: [phase 13](../05-plan/phase-13-capture-explore-and-scenarios.md), P13.T6 to P13.T9.
- Revisit when: people ask for a step type we do not have more than three times, or a team asks to share notebooks.
