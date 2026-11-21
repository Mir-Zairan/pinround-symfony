# Prototype guide

What the clickable prototype shows, how to open it, and the visual system the product must copy. Read this before building any screen.

## Index

- [Why this guide exists](#why-this-guide-exists): the prototype is private, so this guide holds everything a build session needs.
- [How to open the prototype](#how-to-open-the-prototype): the link, the boards, and how to read them in Claude Code.
- [Prototype-only devices](#prototype-only-devices): the "view as" switch, "Big team", and other demo tricks. Do not build them.
- [Simulated parts vs product rules](#simulated-parts-vs-product-rules): what is fake in the demo and what is a real rule.
- [Global layout](#global-layout): the header, navigation per role, buttons in the header, and the toast.
- [Design tokens](#design-tokens): exact colour tokens for light and dark, group colours, fonts, sizes, and radii.
- [Component classes](#component-classes): buttons, chips, segments, tags, pins, switches, cards, rows, bars, and avatars.
- [Screen files](#screen-files): one file per screen, with wireframes, copy, states, and rules.
- [Names used in these docs](#names-used-in-these-docs): the neutral example people and projects.

## Why this guide exists

Lena leads a small team. She opens a build session in Claude Code to make the "Home" screen. The prototype link is private to its owner, so the session may not open it. This guide and the files in `screens/` describe every screen, text, state, and rule from the prototype. A session that reads them can build the same product without the link.

The prototype is the target for look and behaviour. When this guide and the prototype disagree, the prototype wins for looks, and the requirements docs win for rules. Report the difference in the phase hand-off notes.

## How to open the prototype

- Link: in your `CLAUDE.local.md` (private to its owner).
- It is private. Only its owner (and people the owner shares it with) can open it.
- It is a claude.ai Design canvas with four boards:

| Board | File | What it shows |
|---|---|---|
| 1 · pinround app | `project/Main.dc.html` | The whole app: Home, Tickets, ticket page, My reports, Team, Admin, dialogs |
| 2 · Review round | `project/Review.dc.html` | One review round: before/after screenshots, pins, AI suggestions, verdict |
| 3 · Review in full screen | `project/Focus.dc.html` | The same round, one pin at a time, dark full screen |
| Part · Screenshot | `project/Shot.dc.html` | The simulated screenshot used by the other boards (before/after variants) |

How to read it in Claude Code:

1. Use the Artifact tool with action `read` and `url` = the link above. This returns the page.
2. To read one board's source, add `path`, for example `project/Main.dc.html`. Main is about 2,700 lines. Read the template part (top) and the `<script>` part (bottom) separately.
3. `project/canvas.json` lists the boards and their titles.
4. If the tool says you have no access, use this guide and `screens/*.md`. They are complete enough to build from.

The prototype is private. Never copy its names, titles or text into this repository. These docs use neutral examples instead.

## Prototype-only devices

Do not build these. They exist so one person can demo many roles on one page.

- **"Prototype · view as" switch** (header, dashed border). Buttons: `Lead + Admin`, `Tester`, `Developer`, `Manager`, `Admin`. Each button shows one person's roles joined with ` + `. Switching resets the page to a view that role may see, closes popovers, and resets the Home focus to "All". In the product, the signed-in user's roles decide everything. There is no switch.
- **"Big team" toggle** (same header group). It adds 13 simulated people to the team so the "many people" layouts show up. Toast on: `Simulated: 13 more people from the Jira tickets joined the team.` Toast off: `Back to the real team of 3.` In the product, the layouts switch on real team size.
- **Theme prop "dark"** on the canvas. In the product, follow the system theme, with a user override.
- **Labels like "EXAMPLE · MADE UP", "simulated", "made up for the demo"**. They mark demo data. The product has no such labels, except "simulated" screenshots are replaced by real uploads.
- **The "Jira" link in ticket details** points to a real Jira. In the product, it points to the connected Jira site.

## Simulated parts vs product rules

| In the prototype | Simulated or real rule? | What the product must do |
|---|---|---|
| Tickets come from a snapshot file loaded at start | Simulated | Tickets live in the database, synced from Jira or made in pinround |
| Every change is lost on reload | Simulated | Every change is saved and audited |
| Pings in the Home and bell (fixed list per role) | Simulated data | Pings are real records sent by people |
| One example question on one ticket, one example new ticket with 2 pins | Simulated data | Real data |
| Screenshot is one fixed picture (`Shot.dc.html`) | Simulated | Real uploads, processed and stored safely |
| "Capture with pinround" and "Upload a file" both add the same picture | Simulated | Capture comes from the browser plugin; upload takes PNG/JPEG/WebP |
| "AI order" on Home is a fixed score formula | Real rule (v1) | Same formula; AI re-ranking may come later |
| Duplicate finder matches words (3+ shared words and 30%+ of the old title's words) | Real rule (fallback) | Use full-text search plus trigram match; AI when allowed |
| Turn rules, check reasons, verb buttons, team rules, ping limits | Real rules | Build exactly as described in the screen files |
| Local AI check calls `http://localhost:11434/api/tags` | Real behaviour | Same, but only after a user click |
| "Cloud AI" uses the viewer's Claude | Simulated provider | Use the org's configured provider through Symfony AI |
| Admin settings change the app at once | Real behaviour | Same, and each change is written to the activity log |
| Two example teams (Payments team, Mobile team) in manager views | Simulated data | Real teams |
| Dates: "today" is a fixed date | Simulated | Real clock |

## Global layout

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ [logo] pinround   Home  Tickets  My reports(1)  Team  Admin                   │
│                 [New report] [● Local AI: ready] [🔔 3] [view as…] [LM]      │
└──────────────────────────────────────────────────────────────────────────────┘
│ page content (max width 1100–1280 px, 24 px side padding)                     │
                                                    [toast, bottom centre]
```

- **Header**: sticky at top, background `--surface`, bottom border `--outline-var`. Inner max width 1280 px, padding 8 px 24 px, min height 64 px, items wrap on small screens.
- **Logo**: a 26 px rounded square in `--primary` with a pin shape (circle + short line) in `--on-primary`, then the word `pinround` (18 px, bold). Clicking it goes Home.
- **Navigation** (pill buttons, class `.nav`, current page has `aria-current="page"`):
  - Everyone: `Home`.
  - Everyone except admin-only users: `Tickets` (also marked current on a ticket page).
  - Team members (lead, tester, developer): `My reports`, `Team`.
  - Anyone with the Admin role: `Admin`.
  - `My reports` shows a red count tag when questions on your own reports wait for your answer.
- **Right side of header**:
  - `New report` (tonal small button) for team members. Opens "Report a problem".
  - `Local AI: …` chip with a coloured dot. Only shown when AI is on and the admin set "Where the AI runs" to laptop or laptop-then-cloud, and the user is not admin-only. Opens the local AI sheet.
  - Bell icon button (team members) with a red badge count of open pings. Opens the pings popover.
  - Avatar: 36 px circle, initials, `--primary-container` background, full name as tooltip.
- **Loading state**: centred text `Loading tickets…`. Error: `Could not load the tickets.`
- **Toast**: fixed at bottom centre, `--inverse-surface` background, 12 px radius, 14 px text, disappears after 3.6 s. Every action confirms with a toast.

## Design tokens

Colours come from one Material 3 seed, `#2A6F65`, plus fixed "meaning" colours (passed, waiting, ping, AI). Copy these names and values exactly as CSS custom properties.

| Token | Light | Dark |
|---|---|---|
| `--primary` | `#006b5f` | `#82d5c7` |
| `--on-primary` | `#ffffff` | `#003731` |
| `--primary-container` | `#9ef2e3` | `#005047` |
| `--on-primary-container` | `#005047` | `#9ef2e3` |
| `--secondary-container` | `#cce8e2` | `#334b47` |
| `--on-secondary-container` | `#334b47` | `#cce8e2` |
| `--surface` | `#f4fbf8` | `#0e1513` |
| `--s-lowest` | `#ffffff` | `#090f0e` |
| `--s-low` | `#eff5f2` | `#161d1b` |
| `--s-c` | `#e9efec` | `#1a211f` |
| `--s-high` | `#e3eae7` | `#252b2a` |
| `--s-highest` | `#dde4e1` | `#303634` |
| `--on-surface` | `#161d1b` | `#dde4e1` |
| `--on-surface-var` | `#3f4946` | `#bec9c5` |
| `--outline` | `#6f7976` | `#899390` |
| `--outline-var` | `#bec9c5` | `#3f4946` |
| `--inverse-surface` | `#2b3230` | `#dde4e1` |
| `--inverse-on-surface` | `#ecf2ef` | `#2b3230` |
| `--error` | `#ba1a1a` | `#ffb4ab` |
| `--error-container` | `#ffdad6` | `#93000a` |
| `--on-error-container` | `#93000a` | `#ffdad6` |
| `--passed` | `#006c51` | `#66dbb2` |
| `--passed-container` | `#83f8cd` | `#00513c` |
| `--on-passed-container` | `#002116` | `#83f8cd` |
| `--waiting` | `#735c00` | `#ebc23e` |
| `--waiting-container` | `#ffe088` | `#574500` |
| `--on-waiting-container` | `#241a00` | `#ffe088` |
| `--ping` | `#0062a0` | `#9bcbff` |
| `--ping-container` | `#d0e4ff` | `#004a7a` |
| `--on-ping-container` | `#001d34` | `#d0e4ff` |
| `--ai` | `#4f51be` | `#c0c1ff` |
| `--ai-container` | `#e1e0ff` | `#3637a4` |
| `--on-ai-container` | `#06006c` | `#e1e0ff` |
| `--scrim` | `rgba(0,0,0,.32)` | `rgba(0,0,0,.6)` |

**The five status groups** (fixed for every team and company):

| Group id | Label | Colour (dots, bars) | Pill background | Pill text |
|---|---|---|---|---|
| `todo` | To do | `#6f7976` | `--s-highest` | `--on-surface` |
| `doing` | Doing | `#006b5f` | `--secondary-container` | `--on-secondary-container` |
| `check` | Checking | `#2f6fd6` | `--ping-container` | `--on-ping-container` |
| `wait` | Waiting | `#c98a00` | `--waiting-container` | `--on-waiting-container` |
| `done` | Done | `#006c51` | `--passed-container` | `--on-passed-container` |

**Pins**: report and checklist pins use `--error` with a white 2 px border. Review boards use `#b4470a` (orange) for human pins, and `--ai-container` with a dashed `--ai` border for AI suggestions until a person keeps them.

**Type**:
- Body font `Atkinson Hyperlegible Next` (weights 400, 500, 600, 700), fallback `system-ui, -apple-system, 'Segoe UI', sans-serif`. Size 15 px, line height 1.5.
- Mono font `Atkinson Hyperlegible Mono` (400, 500) with slashed zero (`font-feature-settings: 'zero' 1`). Used for ticket keys, versions, commands.
- The prototype loads them from Google Fonts. The product must self-host them.
- Sizes: page title 28–30 px bold (letter spacing −0.015em); section title `.h2` 18 px bold; muted text `.muted` 13 px `--on-surface-var`; small caps-like labels 13 px bold with 0.03em spacing (for example `YOUR NEXT STEP`).

**Radii and spacing**:
- Buttons 20 px (pill).
- Small buttons and segments 16 px.
- Chips 10 px.
- Cards 16 px.
- Big panels and dialogs 18–24 px.
- Inputs 12 px.
- Tags 6 px.
- Page padding 28 px top, 24 px sides, 72 px bottom. Gaps between sections 24–32 px.

**Focus**: every focusable element gets `outline: 2px solid var(--primary); outline-offset: 2px` on `:focus-visible`.

## Component classes

| Class | Look | Use |
|---|---|---|
| `.btn` | 40 px high pill, 14 px semibold, 18 px side padding | All buttons |
| `.btn-filled` | `--primary` background, `--on-primary` text; disabled = 38% opacity | The one primary action |
| `.btn-tonal` | `--secondary-container` background | Secondary actions |
| `.btn-outline` | Transparent, `--outline-var` border | Neutral actions |
| `.btn-text` | Transparent, `--primary` text, 12 px padding | Low-weight actions, links in lists |
| `.btn-sm` | 32 px high, 13 px text | Inside cards and rows |
| `.icon-btn` | 40 px round, transparent | Bell, "⋯" menu |
| `.badge` | 18 px red pill on an icon button corner | Counts on the bell |
| `.nav` | 40 px pill; current = `--secondary-container` | Header navigation |
| `.seg` | 32 px pill; pressed = `--secondary-container` | Segmented toggles (List / Whose turn?) |
| `.chip` | 34 px, 10 px radius, outline; pressed = filled `--secondary-container` | Filters, choices, focus chips |
| `.rail` | 40 px full-width pill row; pressed = filled | Project rail, admin tab rail |
| `.row` | Full-width list row with top border, hover `--s-low` | Ticket rows |
| `.ghead` | Bold group header row with rotating chevron | Collapsible groups |
| `.card` | 16 px radius, `--s-lowest`, `--outline-var` border, 18×20 px padding | Panels |
| `.soft` | 16 px radius, `--s-c` background, no border | Composer boxes, inline forms |
| `.tag` | 11 px bold uppercase-style label, 6 px radius, `--s-highest` | Small markers (EXAMPLE, PRIVATE, group names) |
| `.aichip` | Same shape as tag, `--ai-container` colours | Marks AI content ("AI SUMMARY", "AI ORDER") |
| `.pin` | 26 px numbered circle, red, white border, shadow; centred on its point | Pins on screenshots |
| `.sw` | 44×26 px switch; on = `--primary` | On/off settings (`role="switch"`) |
| `.opt` | Big option card, 1.5 px border; pressed = `--primary` border | Policy choice cards |
| `.bar` | 22 px high flex bar, 6 px radius, 2 px gaps | Group distribution bars |
| `.meter` | 8 px high bar | Wait and workload meters |
| `.av` | 32 px round initials; `.av-lg` 40 px | Avatars |
| `.stack` | Overlapping avatars (−8 px, 2 px surface border) | Team stacks with "+N" |
| `.pick` | Full-width row button, 12 px radius | Rows in pickers and menus |
| `.field` | 12 px radius input/textarea/select, `--outline` border | Form fields |
| `.raw` | Pre-wrapped text, 1.65 line height, max 76ch | Original ticket text |
| `.mono` | Mono font | Keys, versions, commands |
| `.sr` | Screen-reader-only text | Hidden labels |

## Screen files

| File | Screen |
|---|---|
| [screens/home-work.md](screens/home-work.md) | Home for team members (lead, tester, developer), including focus chips, admin card and the manager mini view |
| [screens/home-manager.md](screens/home-manager.md) | Home for managers: "Teams at a glance" |
| [screens/home-admin.md](screens/home-admin.md) | Home for admin-only users |
| [screens/tickets-list-and-whose-turn.md](screens/tickets-list-and-whose-turn.md) | Tickets: List view and "Whose turn?" view |
| [screens/ticket-page.md](screens/ticket-page.md) | One ticket: status, Now/Next, the ask, AI, questions, rounds, next step, people |
| [screens/report-a-problem.md](screens/report-a-problem.md) | Report a problem (new ticket) |
| [screens/my-reports.md](screens/my-reports.md) | My reports (parcel tracking) |
| [screens/team.md](screens/team.md) | Team page: projects, how work moves, people |
| [screens/admin.md](screens/admin.md) | Admin settings, all tabs |
| [screens/review-round.md](screens/review-round.md) | Review round board |
| [screens/full-screen-review.md](screens/full-screen-review.md) | Full-screen review board |
| [screens/dialogs-and-popovers.md](screens/dialogs-and-popovers.md) | Bell, person picker, ping sheet, add project, local AI sheet, share-draft dialog, toast |

## Names used in these docs

The prototype uses real names. These docs replace them:

| Role in the prototype | Name in these docs |
|---|---|
| Team lead (also admin in the demo) | Lena |
| Tester | Mia |
| Developer | Omar |
| Product manager | Raj |
| Org admin | Sara |
| Company | Acme Foods |
| Main project / key | Shop / `SHOP` (keys `SHOP-101`, `SHOP-102`, …) |
| Other linked projects | `APP` (Mobile app), `UX` (UX design), `QA` (Test automation) |
| Team | Lena’s team |
| Current release | `v2026.45.1` |
