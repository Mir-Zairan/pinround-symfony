# Glossary

Every product word in pinround, with its plain meaning. Code, UI copy and docs use these words and no others.

## Index

- [How to use this glossary](#how-to-use-this-glossary): rules for names in code and copy.
- [Terms A–Z](#terms-az): all terms, alphabetical, from "Action" to "Workload".
- [Words we do not use](#words-we-do-not-use): Jira-style words and what we say instead.

## How to use this glossary

- Domain classes, routes and UI labels use these words. A `Pin` is a pin, not a "marker" or "annotation".
- If you need a new word, add it here first, in the same pull request.
- The example people (Lena, Omar, Mia, Raj, Sara) and the example org (Acme Foods, project SHOP) are explained in [personas-and-roles.md](personas-and-roles.md).

## Terms A–Z

**Action** — A button that moves work forward, written as a verb: "Start work", "Send to code review", "Pass it". An action may change the step. See *Verb*.

**Activity ("What happened")** — The list of events on a ticket: who moved it, who asked, who checked. Newest first.

**Add my report there** — On the report page, the choice to add your report (text and pins) as a comment on an existing ticket instead of making a new one.

**Admin** — An organization role. Sets up people, teams, statuses, AI, pings, connections and data rules. Does not need to work on tickets. See [personas-and-roles.md](personas-and-roles.md).

**AI order** — The order of the "Needs you now" list. It comes from the *needs-you score*. The label says "AI ORDER" because AI may re-rank it later; today it is a clear formula.

**AI suggestion pin** — A pin proposed by AI on a review screenshot. It has a dashed border. It counts only after a person presses "Keep as my pin". "Dismiss" removes it.

**Answer** — A reply to a *question*. Sending it closes the question and moves the ticket back to the step it was in.

**Assignee** — The person a ticket is given to. Shown as an avatar. A ticket can have no assignee.

**Big team** — A team with more than 4 people. Then the people filter becomes a "Person ▾" picker with search, and avatars show "+N".

**Check (new ticket check)** — A quick look by the *checker* at a new ticket that has at least one *check reason*. Not an approval gate: the ticket already exists and can move.

**Check reasons** — Why a new ticket gets a check. There are five: *maybe a duplicate*, *missing info*, *no team*, *risky*, *company rule*. See [FR-INT](../01-requirements/functional-requirements.md#int--intake-and-checks).

**Checker** — The person who does the check: the lead of the ticket's team. If the ticket has no team, the *triager*.

**Checklist (Round 1)** — The list made from the pins of the report. The developer marks each item "Fixed"; the tester marks it "OK" or "Not fixed".

**Comment** — A message on a ticket. It does not change the status and sends no notification. Compare *Question*.

**Company steps** — Steps the admin adds for every team. They sit inside the five groups.

**Core steps** — The built-in steps every organization has: Backlog, To do, In progress, Code review, Testing, Needs input, On hold, Done.

**Draft round** — A private round that the assignee makes to check their own work: screenshots, pins, notes and a verdict per screenshot. Only the owner (and their Claude Code, with their token) can see it until they share it.

**Explain it to me** — A private AI answer about a ticket, only for the person who asked. They can share it; then it shows under the summary for everyone.

**Five groups** — To do, Doing, Checking, Waiting, Done. Fixed for every organization, with fixed colors. Every step belongs to exactly one group. Managers compare teams by these groups.

**Focus chips** — Small buttons on Home: All, My work, My team, All teams (if manager), Admin (if admin). They narrow what Home shows. A red number appears on a chip when a hidden part needs you.

**Give it to…** — The action that sets the assignee. Opens a person picker sorted by how much already waits on each person.

**Group** — See *Five groups*.

**Hand-off** — The moment a ticket leaves its assignee (for example "Send to code review"). If the assignee has an unshared draft round, pinround asks: share it, or keep it private.

**In short** — The short AI summary at the top of "The ask" on a ticket. It says who or what wrote it.

**Job** — What a person does in a team: tester, developer, other. A job is not a permission. It helps pinround know who tests and who reviews.

**Lead (team lead)** — A team role. Plans work, gives tickets to people, checks unclear new tickets, may move a ticket to any step, may add team steps if the policy allows.

**Lead check** — See *Check*. The admin can switch on "every new ticket needs a lead check"; then a ticket is not given to anyone until checked.

**Local AI (laptop AI)** — An AI model (for example `qwen3:4b`) that runs with Ollama on a person's own laptop. The browser talks to it; the server never does. See [ADR-0019](../03-adr/0019-browser-local-ai.md).

**Manager** — An organization role. Sees every team as one bar in the five groups ("Teams at a glance"). Can read and comment, does not move tickets.

**Member** — A team role. Works on tickets: reports, builds, tests, reviews, comments.

**My reports** — The page that shows every ticket you reported, like parcel tracking: Reported → Building → Checking → Done.

**Needs input** — A core step in the Waiting group. The ticket waits for information from the reporter, a customer or an owner.

**Needs-you score** — The number that orders "Needs you now". It adds a base for the kind of task, a weight for priority, a bonus for releases, and half a point per day of waiting (max 60 days). See [FR-HOME-03](../01-requirements/functional-requirements.md#home--home-page).

**Next** — The line on a ticket that says what happens after the current step: "Lena reviews the code, then Mia tests it."

**Now** — The line on a ticket that says who does what at this moment: "Omar builds it."

**On hold** — A core step in the Waiting group. The lead decides whether work goes on.

**Organization (org)** — A company or group that uses pinround. All data belongs to exactly one organization.

**Ping** — A short, direct nudge to a person about one ticket ("Could you look at SHOP-88?"). Limited to one per person per ticket every N hours. Clears itself when that person acts on the ticket.

**Pin** — A numbered dot on a screenshot with a short note: what is wrong (or right) at that spot.

**Policy (step policy)** — The admin's choice of who may change steps: *Admin only* (locked), *Company steps plus team steps* (extend), *Team decides* (team).

**Priority** — Blocker, Critical, Major, Normal, Minor (Trivial is read from Jira but shown as Minor-like weight 0).

**Private explanation** — See *Explain it to me*.

**Project** — A set of tickets with one key, for example SHOP. Ticket keys are `SHOP-1`, `SHOP-2`…

**Queue (walk-through)** — When you press "Start reviews" (or another "Start …" button) on Home, pinround opens the tickets of that group one after another. A bar shows "2 of 4" with Previous, Next ticket, Finish and Stop.

**Question** — A message to one named person. It pauses the ticket (status "Waiting · question to Mia") until that person answers.

**Release** — A planned version, for example `v2026.47.1`. Tickets can belong to a release. Home shows the lead a bar of the current release.

**Reporter** — The person who reported the ticket.

**Resolve** — Folds a finished comment thread for everyone. It can be opened again.

**Risky** — A check reason. The report mentions security, data loss, leaks, passwords, crashes or outages (by word list or AI). Security words also make the ticket *team-only*.

**Round** — One pass of showing and checking work on screenshots. Round 0 is the report. Round 1 is the checklist from its pins. Later rounds are review rounds. A draft round is private until shared.

**Round 0 (report)** — The screenshot and pins from the person who reported the problem.

**Rules (team rules)** — Guards on actions: *only the tester moves a ticket to Done*, *nobody approves their own code review*, *code review must happen before testing*.

**Same problem · close this one** — The checker's action to close a new ticket as a duplicate of an older one. Both tickets get a comment.

**Step** — A named place in the workflow, for example "Code review". Steps are core, company or team steps. Each step belongs to one group.

**Stuck** — On My reports: an open ticket with no change for more than 7 days.

**Summary** — See *In short*. Earlier summaries are kept in a history. A lead or the reporter can make a shared explanation the main summary.

**Team** — A group of people in an organization with one lead, linked projects and its own steps (if the policy allows).

**Team-only (visibility)** — A ticket that only the team's members, admins, and the people working on it (including the reporter) can see. Used for security problems. Managers outside the team do not see it. Exact rule: [ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate).

**Team steps** — Steps a lead adds for their team, inside the five groups, when the policy is *extend* or *team*.

**Ticket** — One piece of work: a bug, story or task, with a key, a title, a step, people, rounds and messages.

**Triager** — The person the admin chooses to check new tickets that have no team.

**Turn (whose turn)** — The person who must act next on a ticket. Computed from the step and open questions. See [FR-WF-08](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns).

**Type** — Bug, Story or Task.

**Verb** — See *Action*. We name what a person does, not the status it leads to.

**Verdict** — A judgement on a pin or screenshot. On review pins: *Fixed* / *Still wrong*. On checklist items: *OK* / *Not fixed*. On draft screenshots: *Looks right* / *Not right yet*. On a review section: *Pass* / *Needs fix* / *Question*.

**Viewer** — A team role. Can read and comment. Cannot move tickets.

**Waiting** — One of the five groups. Also shown on a ticket with an open question: "Waiting · question to Mia".

**Whose turn?** — The Tickets view grouped by the person who must act next, with how long the oldest ticket waits.

**Workload** — The number of open tickets where it is a person's turn. Shown as a bar; red at 20 or more.

## Words we do not use

| Avoid | Say instead | Why |
|---|---|---|
| Status transition | Action / move | People think in actions. |
| Issue | Ticket | One word everywhere. |
| Backlog grooming | Planning | Plain English. |
| Triage inbox | Check (of new tickets) | It is not a gate. |
| Annotation, marker | Pin | One word everywhere. |
| Resolution | Done / closed as duplicate | Plain English. |
| Watcher | (follows My reports / mutes updates) | Fewer concepts. |
| Epic, story points | (not in v1) | See [vision non-goals](vision.md#non-goals). |
