# Screen parts: dialogs, popovers, toast

Small overlays used on several screens.

## Index

- [Shared look](#shared-look): scrim, radius, sizes.
- [Pings popover (bell)](#pings-popover-bell): all ping groups.
- [Person picker](#person-picker): filter mode and assign mode.
- [Ping sheet](#ping-sheet): send one ping.
- [Add a project](#add-a-project): link Jira projects to the team.
- [Local AI sheet](#local-ai-sheet): set up and check Ollama on the laptop.
- [Share draft dialog](#share-draft-dialog): asked when a ticket with a private draft leaves its owner.
- [Toast](#toast): short confirmations.

## Shared look

- Modal dialogs: a fixed full-screen `--scrim` layer, centred box, `--s-c` background, 24 px radius, 24 px padding, `role="dialog"`, `aria-modal="true"`, labelled by their h2 (22 px bold).
- Width: 440–540 px, max height = screen − 32 px with inner scroll.
- `Cancel`/`Close` is always a text button on the left of the primary button.

## Pings popover (bell)

- Opened by the bell icon in the header. A non-modal popover under the header on the right, width min(420 px, screen − 32 px), max height 70vh, `--s-c`, 20 px radius, shadow.
- Title `Pings · N open` and a text button `Close`.
- Muted: `Grouped by person and ticket. A ping clears itself when you act on that ticket.`
- Empty: `No open pings.`
- One card per group (same content as on Home): sender, `pinged you` / `N pings`, time, the newest text in quotes, key, `Done` (toast `Marked done.`), and `Open` (opens the ticket and closes the popover).
- **Grouping rule**: one group per sender + ticket. Groups are sorted by number of pings (most first), then newest.
- **Auto-clear rule**: when the admin switch is on, any action you take on a ticket clears all your pings on that ticket.

## Person picker

Used in two modes:

| | Filter mode | Assign mode |
|---|---|---|
| Opened from | `Person ▾` chip in Tickets (more than 4 people) | `Give it to…` on a ticket |
| Title | `Show tickets of…` | `Give <key> to…` |
| Sub | `Sorted by open tickets assigned to them.` | `The ticket moves to their list. Sorted by how much is already waiting on them.` |
| People | all team members | team members except the tester |
| Sort | most open assigned tickets first | least waiting first |
| Right text | `N open` | `N waiting` |
| Pick result | filters the list by that person | assigns; Backlog tickets move to To do; toast `Given to <first>.`; log `gave <key> to <name>` |

- A search field `Find a person` (filters by name).
- Rows are `.pick` buttons with an avatar. In filter mode, the current person is pressed.
- `Cancel`.

## Ping sheet

- Title `Ping <name>`. Sub: `<key> · <title>`.
- Label `Message`, a textarea pre-filled with a draft:
  - From the ticket page: `Hi <first>, could you look at <key>? Now: <now text>.`
  - From My reports: `Hi <first>, any news on <key>? I reported it. Now: <now text>.`
- Muted: `<name> gets one notification. You can ping again after N hours (set by your admin).`
- `Cancel` / `Send ping`. Toast `Pinged <name>. One notification sent.` Log `pinged <name> on <key>`.
- After sending, that person's `Ping` button on this ticket is replaced by `Pinged · again in N h`.
- **Rate limit rule**: one ping per sender, per person, per ticket, every N hours (admin: 1/4/8/24 h). Quiet hours (admin switch) hold delivery between 18:00 and 09:00.

## Add a project

- Title `Add a project`. Sub: `Projects from your Jira site. Pick one or more.`
- A search field `Find by key or name`. It matches key or name.
- A list of not-yet-linked projects (key mono + name) as toggle `.pick` buttons (multi-select).
- `Cancel` / `Add project`. The button reads `Add N projects` when several are picked, and is disabled when none is.
- Toast `Added SHOP, APP`. New rows say `Just added · tickets load on the next Jira sync`.

## Local AI sheet

Opened by the header chip `Local AI: …`. The chip shows only when the admin set "Where the AI runs" to laptop or laptop-then-cloud.

- **Chip states**:

| State | Text | Dot |
|---|---|---|
| not checked | `Local AI: not checked` | `--outline` |
| checking | `Local AI: checking…` | `--outline` |
| ready | `Local AI: ready` | `--passed` |
| no model / not found | `Local AI: not found` | `--error` |

- **Sheet**:
  - Title `AI on your laptop`.
  - Sub `Your company runs pinround’s AI on each laptop, for free.`, then:
    - Laptop-then-cloud: `Until it runs, pinround uses the company cloud AI.`
    - Laptop only: `Until it runs, AI features stay hidden for you.`
  - **Status line** (bold):
    - `Not checked yet.`
    - `Looking for Ollama on this laptop…`
    - `Ollama runs on this laptop. pinround uses <model>. Ticket text stays on this laptop.`
    - `Ollama runs, but it has no model yet. Do step 2.`
    - `pinround could not reach Ollama on this laptop.`
  - With more than one model: chips to pick the model.
  - **Steps** (numbered):
    1. `Install Ollama from ollama.com. It runs in the background.`
    2. `Get the model once, in a terminal: ollama pull qwen3:4b (about 2.5 GB).`
    3. `Let pinround talk to it: set OLLAMA_ORIGINS to your pinround address and restart Ollama. Your IT team can do this for everyone.`
    4. `Press “Check again”. Your browser may ask if this page may talk to apps on your computer. Say yes.`
  - Muted (prototype-only): the page's safety rules may block the check.
  - `Close` / `Check again` (disabled while checking).
- **Check rule**:
  - The check runs only on a click.
  - It calls `GET http://localhost:11434/api/tags` with a 3 s timeout.
  - It picks the first `qwen3*` model, else `phi4*`/`gemma*`, else the first model.
- **Use rule**: when local AI is ready and the policy allows it, every AI call goes to `POST http://localhost:11434/api/generate` with `stream:false`, `think:false`, and `format:"json"` for JSON answers. Any `<think>…</think>` text is removed. Otherwise the call uses the cloud AI.

## Share draft dialog

- It opens when the owner of a **private** draft round with at least one screenshot moves the ticket to another step, and has not decided yet.
- Title `Share your draft round?`.
- Text: `Your draft round has N screenshot(s). Share it with the team as round R, or keep it private. Private notes stay readable for you and Claude Code.`
- Buttons:
  - `Cancel`: no move.
  - `Keep private and move` (outline): does the move. The draft stays private. Toast `Moved to <step>. Your draft stays private.`
  - `Share and move to <step>` (filled): shares the draft as round R and does the move. Toast `Moved to <step>. Your draft is now round R for the team.`
- After either choice the dialog does not come back for that draft.

## Toast

- One at a time, bottom centre, `--inverse-surface` background, `role="status"`.
- It disappears after 3.6 s (2.8 s on the review board). A new toast replaces the old one.
- Every state-changing action shows one. The texts are listed in each screen file.
