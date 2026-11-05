# UX principles and design system

How pinround screens look, behave and talk, and the human reason behind each rule.

## Index

- [Why this matters](#why-this-matters): the story of a tired lead at 9 a.m.
- [The twelve principles](#the-twelve-principles): each rule with its reason (psychology) and how to apply it.
- [Design tokens](#design-tokens): M3 colors from seed #2A6F65, light and dark names as in the prototype.
- [The five group colors](#the-five-group-colors): fixed colors for To do, Doing, Checking, Waiting, Done.
- [Typography, spacing and shape](#typography-spacing-and-shape): font, sizes, radius, layout widths.
- [Components](#components): the inventory (chip, seg, pill, card, pin, AI chip, tag, avatar stack, bar, dialogs…).
- [States](#states): empty, loading, error, disabled, offline, AI running.
- [Accessibility](#accessibility): WCAG 2.2 AA rules we test.
- [Copy guidelines](#copy-guidelines): how we write, with good and bad examples.
- [Review checklist for a new screen](#review-checklist-for-a-new-screen): ten questions before merge.

## Why this matters

It is 9 a.m. Lena has coffee and twelve minutes before stand-up. A normal board shows her 40 cards. Her brain tries to sort them, gets tired, and she opens chat instead. pinround's Home shows her "7 things wait on you, Lena", the top five with a reason each, and one button that walks her through her code reviews. She is done before stand-up. Every rule below exists to protect those twelve minutes.

## The twelve principles

### 1. One primary action per screen part

**Rule:** each card or box has at most one filled (primary) button. Other actions are outlined, text buttons, or in a "⋯" menu. My reports cards show at most two visible buttons.
**Why:** people decide faster when one option is clearly "the next step" (Hick's law: decision time grows with the number of choices).
**Apply:** the "YOUR NEXT STEP" box on a ticket shows the verb for the viewer first; "Move to another step…" is a quiet text button.

### 2. Top 5, then groups

**Rule:** lists that need action show the top 5 first, ordered, each with a "Why" line. The rest is grouped by kind with a count and a "Start …" button.
**Why:** choice overload; working memory holds about 4–5 items. Grouping by kind lets people do one kind of task in a row (less switching cost).
**Apply:** Home "Needs you now" and "Also waiting on you"; pings top 3 + "Show N more"; My reports groups show 4 + "Show more".

### 3. Verbs, not statuses

**Rule:** buttons name what the person does ("Send to code review"), never the target status ("Move to Code Review Phase").
**Why:** people think in actions and goals, not in state machines. A verb also tells them it is their move.
**Apply:** every transition has a verb label per role. The status pill only reports where the ticket is.

### 4. Now and Next

**Rule:** every ticket shows "Now: <who> <does what>" and, when useful, "Next: …".
**Why:** recognition over recall: nobody has to remember the workflow. It also makes waiting visible.
**Apply:** generated from the turn rules ([FR-WF-08](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)); never hand-written per ticket.

### 5. Show progress as a path

**Rule:** things a person waits for are shown as a short path with the current point marked (Reported → Building → Checking → Done).
**Why:** the goal-gradient effect: people feel calmer and more engaged when they see how far something has come. Parcel tracking taught everyone this pattern.
**Apply:** My reports cards; checklist "2 of 3 checked OK"; queue bar "2 of 4".

### 6. Waiting is visible and has an owner

**Rule:** a question pauses a ticket and names who must answer. "Stuck" (no change > 7 days) is its own group.
**Why:** unowned waiting is the biggest hidden cost in teams (nobody feels responsible).
**Apply:** status "Waiting · question to Mia"; Whose turn? view with oldest-wait meter.

### 7. One Home for all roles

**Rule:** no mode switches. A person with several roles sees one Home with a part per role, plus focus chips to narrow it.
**Why:** out of sight is out of mind; modes cause "wrong mode" errors.
**Apply:** [personas-and-roles.md](personas-and-roles.md#multi-role-behaviour).

### 8. Red only when it matters

**Rule:** red counts appear only when something hidden needs you (a focus chip hiding waiting work, the bell, the My reports badge for questions). Never for "new" or "updated".
**Why:** alarm fatigue: if everything is red, nothing is.
**Apply:** "changed recently" is grey text; risky new tickets go up in order, not red.

### 9. Calm notifications

**Rule:** pings are grouped by person and ticket, limited (one per person per ticket every N hours), cleared when you act, held during quiet hours. Comments send no notification; questions send one.
**Why:** constant interruptions cost focus time (each switch costs minutes to recover).
**Apply:** [FR-PNG](../01-requirements/functional-requirements.md#png--pings-and-notifications).

### 10. Plain words

**Rule:** short sentences, active voice, everyday words, B1 English. Product words only from the [glossary](glossary.md). Explain jargon or avoid it.
**Why:** many users are not native speakers or not technical; clear text cuts mistakes.
**Apply:** see [copy guidelines](#copy-guidelines).

### 11. Not a Jira card

**Rule:** no dense cards with five badges, no columns of status names, no tiny type. Rows are calm: key, title, one meta line, one person.
**Why:** dense cards make every item look equally important (no visual hierarchy), so nothing stands out.
**Apply:** the list is grouped by step with a plain hint ("Being checked"); the ticket page reads top-down like a short letter.

### 12. AI helps, a person decides, and it is labelled

**Rule:** every AI output carries an AI chip ("AI SUMMARY", "AI ORDER", ✦ on suggested fields, dashed AI pins). Nothing AI suggests takes effect without a click.
**Why:** trust needs knowing the source; automation bias makes people accept wrong suggestions if they look like facts.
**Apply:** [ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md).

## Design tokens

Colors follow Material 3 tonal palettes generated from the seed **#2A6F65** (teal-green). Token names below are the CSS custom properties used in the prototype; the product uses the same names in `assets/styles/tokens.css`. Values are the prototype's light values (dark values are generated from the same palettes; see the prototype for the full set).

| Token | Light value | Use |
|---|---|---|
| `--primary` | `#006b5f` | Primary buttons, current nav, focus ring, progress |
| `--on-primary` | `#ffffff` | Text on primary |
| `--primary-container` | `#9ef2e3` | "YOUR NEXT STEP" box, avatar of me |
| `--on-primary-container` | `#005047` | Text on primary container |
| `--secondary-container` | `#cce8e2` | Tonal buttons, pressed chips, avatars |
| `--on-secondary-container` | `#334b47` | Text on it |
| `--surface` | `#f4fbf8` | Page background |
| `--s-lowest` | `#ffffff` | Cards, rows |
| `--s-low` | `#eff5f2` | Soft areas, comments, list hover |
| `--s-c` | `#e9efec` | Dialogs, popovers |
| `--s-high` | `#e3eae7` | Search fields, segmented control track |
| `--s-highest` | `#dde4e1` | Neutral tags, empty bar parts |
| `--on-surface` | `#161d1b` | Main text |
| `--on-surface-var` | `#3f4946` | Secondary text |
| `--outline` / `--outline-var` | `#6f7976` / `#bec9c5` | Borders, dividers |
| `--inverse-surface` / `--inverse-on-surface` | `#2b3230` / `#ecf2ef` | Toasts |
| `--error`, `--error-container`, `--on-error-container` | `#ba1a1a`, `#ffdad6`, `#93000a` | Errors, counts that need you, "Still wrong" |
| `--passed`, `--passed-container`, `--on-passed-container` | `#006c51`, `#83f8cd`, `#002116` | Passed, "Fixed", "Looks right" |
| `--waiting`, `--waiting-container`, `--on-waiting-container` | `#735c00`, `#ffe088`, `#241a00` | Waiting, questions, stuck, check box border |
| `--ping`, `--ping-container`, `--on-ping-container` | `#0062a0`, `#d0e4ff`, `#001d34` | Pings |
| `--ai`, `--ai-container`, `--on-ai-container` | `#4f51be`, `#e1e0ff`, `#06006c` | AI chips, AI pins, AI boxes |
| `--scrim` | `rgba(0,0,0,.32)` | Behind dialogs |

Human pins use a fixed orange (`#b4470a` fill, white border) in both themes so they stand out on any screenshot.

Dark theme: the `.dark` set in the prototype; the product follows `prefers-color-scheme` with a manual override saved per user.

## The five group colors

Fixed for every organization so that a manager reads every team the same way.

| Group | Color | Pill background / text |
|---|---|---|
| To do | `#6f7976` (grey) | `--s-highest` / `--on-surface` |
| Doing | `#006b5f` (teal) | `--secondary-container` / `--on-secondary-container` |
| Checking | `#2f6fd6` (blue) | `--ping-container` / `--on-ping-container` |
| Waiting | `#c98a00` (amber) | `--waiting-container` / `--on-waiting-container` |
| Done | `#006c51` (green) | `--passed-container` / `--on-passed-container` |

Color is never the only signal: pills also show the step name, bars show numbers.

## Typography, spacing and shape

- **Fonts:** Atkinson Hyperlegible Next (text) and Atkinson Hyperlegible Mono (keys, code). Self-hosted, `font-display: swap`. Chosen for legibility (clear letter shapes, made for low vision).
- **Base size** 15 px, line height 1.5. Titles: page 28–30 px/700; section `h2` 18 px/700; ticket title 28 px. Never below 12 px.
- **Spacing scale (px):** 2, 4, 6, 8, 10, 12, 16, 20, 24, 28, 32, 48, 72.
- **Radius:** chips 10; buttons 20 (pill); cards and boxes 16; dialogs 24; tags 6.
- **Widths:** content max 1200 px (Home, ticket), 980 px (forms, My reports), list 1280 px with a 220–280 px project rail. Layouts wrap with flexbox; no horizontal scroll at 360 px.
- **Elevation:** almost flat. Only popovers, dialogs and toasts have a shadow.

## Components

Build these once as Twig components ([frontend.md](../02-architecture/frontend.md)). Names match the prototype CSS classes.

| Component | What it is | Where |
|---|---|---|
| `btn` (filled / tonal / outline / text, sm) | Buttons; one filled per part | Everywhere |
| `chip` | Toggle filter or choice; `aria-pressed` | Filters, focus chips, form choices, verdicts |
| `seg` | Segmented control | List / Whose turn?, After / Before, view-as (prototype only) |
| Status pill | Step name with a dot in group colors | Ticket header |
| `card` | Soft box with radius 16 | Boxes on ticket page, admin |
| `row` | Full-width clickable list row | Lists |
| `pin` marker | Numbered circle on a screenshot; human (orange) or AI (dashed, AI colors) | Rounds, report form |
| `aichip` | Small "AI SUMMARY" / "AI ORDER" label | AI outputs |
| `tag` | Small uppercase label | "MADE IN THIS DEMO"-style notes, "PRIVATE", "EXAMPLE", counts |
| Avatar / `stack` | Initials circle; overlapping stack with "+N" | People, team |
| `bar` | Horizontal stacked bar in group colors with counts | Release, manager, workload |
| Switch (`sw`) | On/off with `role="switch"` | Admin settings |
| Person picker dialog | Search + list sorted by load | Give it to…, Person filter |
| Ping sheet | Message + rate-limit note | Ping |
| Bell popover | Grouped pings | Header |
| Queue bar | "Start reviews · 2 of 4" with Previous/Next/Finish/Stop | Ticket page in a walk-through |
| Progress dots | Reported → Building → Checking → Done | My reports |
| Toast | One-line confirmation, bottom center, 3.6 s | After actions |
| Field | Input, textarea, select with label | Forms |

## States

| State | Rule |
|---|---|
| Empty | Say what will appear and how to make it ("When you report a problem, you can follow it here until it is fixed."). Dashed border box. |
| Loading | Server-rendered pages appear complete. Async parts show a short text ("Loading tickets…"), never a spinner longer than 300 ms without text. |
| Error | Plain sentence, what happened and what to do. Keep the user's input. |
| Disabled | Button disabled only with a visible reason nearby (rule note: "Team rule: nobody approves their own code."). |
| AI running | "Thinking…" or streamed text; a Stop button for long answers. |
| AI off | Hide the AI button and say why once ("Your admin turned AI help off."). |
| Offline / live update lost | Small banner "Updates paused. Reload to see the latest." |

## Accessibility

Target: **WCAG 2.2 AA** ([NFR-A11Y-01](../01-requirements/non-functional-requirements.md)).
- Text contrast ≥ 4.5:1, large text and UI parts ≥ 3:1, in both themes.
- Visible focus ring (`2px solid --primary`, offset 2 px) on every interactive element. Focus not hidden by sticky bars (2.4.11).
- All actions work with keyboard. Dialogs trap focus and close with Esc.
- Targets at least 24×24 px (2.5.8); our buttons are 32–40 px high.
- Screenshots with pins: each pin is a button with an `aria-label` ("Pin 2: Shipping shows twice"); the pin list next to the image repeats every note as text.
- Live regions for toasts (`role="status"`).
- Never rely on color alone (pills have text, bars have numbers, verdicts have words).
- Works at 360 px width and 200% zoom.

## Copy guidelines

- Point first. One idea per sentence. Active voice. Present tense.
- Use names and verbs: "Omar builds it", not "Assigned to developer, in progress".
- Say what happens next after any action, in the toast.
- Numbers as digits. Dates like "5 Nov". Days waiting as "waiting 12d" in rows, "for 12 days" in sentences.
- No exclamation marks except in rare good news. No "Oops".

| Bad | Good |
|---|---|
| "Status changed to Code Review Phase." | "Sent to code review. Lena's turn now." |
| "Issue requires additional information from reporter." | "Waiting · question to Mia" |
| "Are you sure you want to proceed?" | "Share your draft round?" + what each choice does |
| "Error 403: Forbidden" | "Only the tester can move a ticket to Done. Mia does that." |
| "Triage required" | "Lena takes a quick look, because maybe the same as an older ticket." |
| "AI-generated content may be inaccurate." | "In short · AI SUMMARY · written by AI when the ticket was reported" |

## Review checklist for a new screen

1. Is there exactly one primary action per part?
2. Does every ticket mention say what happens Now (and Next if useful)?
3. Are long lists cut to a top N with groups or "Show more"?
4. Are all words in the glossary? No status jargon?
5. Is every AI output labelled and every AI suggestion optional?
6. Is red used only for things that need the viewer?
7. Keyboard-only walkthrough done? Focus visible?
8. Contrast checked in light and dark?
9. Works at 360 px?
10. Does it match the prototype screen named in the task? Differences noted in hand-off?
