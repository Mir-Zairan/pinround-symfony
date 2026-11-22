# Vision

Why pinround exists, who it is for, what it does differently, and how we know it works.

## Index

- [A Tuesday with a normal ticket tool](#a-tuesday-with-a-normal-ticket-tool): the story of the problem.
- [Who suffers today](#who-suffers-today): the people and what hurts each of them.
- [What pinround is](#what-pinround-is): the product in one paragraph.
- [What pinround does differently](#what-pinround-does-differently): the eight ideas that shape every screen.
- [The same Tuesday with pinround](#the-same-tuesday-with-pinround): the story again, with pinround.
- [Success measures](#success-measures): numbers we watch to know it works.
- [Non-goals](#non-goals): what pinround will not try to be.
- [Hackathon context](#hackathon-context): the first deadline and what it demands.
- [Where to read next](#where-to-read-next): links to the next documents.

## A Tuesday with a normal ticket tool

Mia is a tester at Acme Foods. She finds that the order total on the shop page is too high. She opens the ticket tool, picks a project, a type, a component, a priority, and writes three paragraphs. She cannot paste the screenshot in the right place, so she writes "see the attached image, top right, the second number".

Omar, a developer, opens the ticket two days later. He reads the three paragraphs twice. He is not sure which number Mia means. He asks in a comment. The comment does not change anything, so the ticket just sits there. Nobody sees that it waits for Mia.

Lena, the team lead, opens her board in the morning. She sees forty cards in eight columns. Each card shows a key, a status and a small avatar. She cannot tell which three cards need her today. Raj, the product manager, asks "how is the shop team doing?" Lena exports a list to a spreadsheet to answer him.

None of these people did anything wrong. The tool made them guess.

## Who suffers today

| Person | What hurts today |
|---|---|
| Reporter (tester, support, anyone) | Long forms. Text instead of pictures. No idea what happens after "Create". |
| Developer | Unclear asks. Has to find the exact spot on the screen. Questions get lost. |
| Tester | Re-checks everything instead of the exact spots that changed. Verdicts live in comments. |
| Team lead | Too many cards, no order. Must chase people by chat. Triage inbox becomes a queue in front of the team. |
| Manager | Every team uses different statuses. Comparing teams needs a spreadsheet. |
| Admin | Many settings, no clear rules about who may change what. |
| New joiner | Status words ("Awaiting implementation", "Approval technical owner") mean nothing to them. |

Read the full personas in [personas-and-roles.md](personas-and-roles.md).

## What pinround is

pinround is a screenshot-first review and ticketing tool. Work moves in **rounds**: someone shows what is wrong on screenshots with numbered **pins**; the developer fixes it; the tester checks each pin and gives a **verdict**. Every screen answers one question in plain words: *what do I do next?* The goal is to make developing, testing, triaging, planning and managing work easier to understand than in any other ticket tool.

## What pinround does differently

1. **Pictures first.** A problem is a screenshot with numbered pins and a short note per pin. The pins become the checklist for the fix and the test. See [glossary: round, pin](glossary.md).
2. **Verbs, not statuses.** People press "Send to code review" or "Pass it", not "change status to Testing". The status follows the action.
3. **Now and Next on every ticket.** Each ticket says "Now: Omar builds it. Next: Lena reviews the code, then Mia tests it." Nobody has to know the workflow by heart.
4. **Top 5, then groups.** Home shows the five things that need you most, with a reason for each. The rest is grouped by kind, and one button walks you through a group one ticket at a time.
5. **Questions pause work.** A question goes to one person and puts the ticket on "Waiting". The answer moves it back. Waiting work is never invisible again.
6. **Report fast, check only when unclear.** Anyone can report a problem. It becomes a ticket at once. A lead only takes a quick look when something is unclear (maybe a duplicate, missing info, no team, sounds risky).
7. **One Home for all your roles.** A person can be a developer and an admin, or a lead and a manager. They see one Home with a part for each role, and small focus chips to narrow it. No mode switch.
8. **Five groups for everyone.** Teams may have their own steps, but every step sits in one of five fixed groups: To do, Doing, Checking, Waiting, Done. Managers compare teams by these five groups, without a spreadsheet.

Two more ideas run through everything:

- **Plain words.** Short sentences, everyday words, no status jargon. See [ux-principles.md](ux-principles.md).
- **AI as a helper, never a boss.** AI writes a short summary, explains a ticket privately, suggests duplicates and fields. A person always confirms. Organizations that cannot pay for an AI service can run a small model on each laptop. See [ADR-0018](../03-adr/0018-ai-provider-abstraction.md) and [ADR-0019](../03-adr/0019-browser-local-ai.md).

## The same Tuesday with pinround

Mia clicks "Report a problem", captures the shop page and drops two pins: "1. Total is 240, items add up to 120" and "2. Shipping shows twice". While she types the title, pinround shows one older ticket that looks similar. It is a different page, so she goes on. The preview says: "It becomes ticket SHOP-88 right away. Lena takes a quick look, because maybe the same as an older ticket." She presses "Create ticket".

Lena's Home says "7 things wait on you, Lena". Number 2 is "Check the new ticket from Mia". She sees it is not a duplicate and presses "Looks fine", then "Give it to…" and picks Omar, who has the least waiting work.

Omar opens SHOP-88. The two pins are his checklist. He fixes the code with Claude Code. He starts a private **draft round**, adds after-screenshots and marks one "Not right yet". He tells Claude Code "read my pinround notes for SHOP-88". Claude Code reads the note, fixes the last bit and adds a new screenshot. Omar presses "Send to code review" and chooses "Share as round 1".

Mia gets the round. She looks only at the two pinned spots, before and after. She marks both "Fixed" and presses "Pass it". Raj opens his Home and sees the shop team bar: most work in Doing and Checking, little waiting. He does not need to ask anyone.

## Success measures

We measure these per organization after the first month. Targets are first guesses; we review them after the pilot.

| Measure | Target | How we measure |
|---|---|---|
| Time from report to first action by the right person | Median under 1 working day | Ticket events (`created` → first transition or check) |
| Tickets waiting on a question for more than 3 days | Under 10% of open tickets | Turn read model, open questions |
| Back-and-forth comments before work starts | 30% fewer than in the old tool | Count of questions before first "Start work" |
| Reports created without help or training | 90% of first reports complete (title + pin or detail) | Intake check reasons "info" rate |
| Lead time in "Checking" group | Down 25% vs. old tool | Ticket events per group |
| People who say "I know what to do next" | 8 of 10 in a short survey | In-app one-question survey after 2 weeks |
| Manager status questions by chat | Fewer, self-reported | Survey |

## Non-goals

- pinround is not a full project portfolio tool (no Gantt charts, no budgets, no time sheets).
- pinround is not a code host or CI system. It links to them.
- pinround does not copy Jira's look, fields or status names. It can sync with Jira while a company moves over ([ADR-0025](../03-adr/0025-jira-integration.md)).
- pinround does not let AI change tickets on its own. AI suggests; a person decides ([ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md)).
- pinround does not aim at public, anonymous bug reporting in version 1. Reporters are people in the organization.
- No custom-field builder in version 1. A few fixed fields keep forms short.

## Hackathon context

The first public milestone is the Symfony AI & MCP Hackathon, deadline **22 November 2026, 18:00 CET**. Entries must use Symfony AI, the official PHP MCP SDK (`mcp/sdk`) and Hugging Face. The hackathon slice is a thin, working path through the product:

1. Log in, one organization and one team.
2. Create a ticket, see it in the list and on the ticket page.
3. Upload screenshots, drop pins, use a private draft round.
4. Get an AI summary and intake suggestions through Symfony AI with a Hugging Face model, or a local Ollama model.
5. Let Claude Code read the round notes, add a screenshot and set a verdict through pinround's MCP server.

Tasks in the plan that belong to this slice are marked 🏁. See [constraints.md](../01-requirements/constraints.md) (C-01) and the [plan](../05-plan/README.md).

## Where to read next

- [glossary.md](glossary.md): all product words.
- [personas-and-roles.md](personas-and-roles.md): who uses pinround and what each role may do.
- [ux-principles.md](ux-principles.md): why the screens look and talk the way they do.
- [functional-requirements.md](../01-requirements/functional-requirements.md): the full list of what the system shall do.
- [prototype guide](../04-prototype/prototype-guide.md): the clickable prototype, screen by screen.
