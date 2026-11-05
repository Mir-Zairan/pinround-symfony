# Screen: Report a problem

Anyone in a team reports a problem with a screenshot and pins. It becomes a ticket the moment they send it. Nobody approves it first.

## Index

- [Who sees it](#who-sees-it): who can open it, and how.
- [Wireframe](#wireframe): the form from top to bottom.
- [Fields and copy](#fields-and-copy): every field, chip and text.
- [Duplicate suggestions](#duplicate-suggestions): live matches and "Add my report there".
- [Where it goes](#where-it-goes): team, person, project, type, priority, and AI suggestions.
- [Check reasons and the preview sentence](#check-reasons-and-the-preview-sentence): the exact rules.
- [Create ticket](#create-ticket): what the new ticket looks like and where you land.
- [States](#states): empty, AI running, AI off.
- [Product notes](#product-notes): where the prototype simplifies.

## Who sees it

- Team members (lead, tester, developer), through the header button `New report` or the `Report a problem` button on My reports.
- In the product, anyone in the organization with at least the Member role in some team may report.
- Every time the page opens it starts empty.

## Wireframe

```
Report a problem                                                   (h1 28 px)
Anyone can do this. It becomes a ticket as soon as you send it. Nobody has to approve it first.

What is wrong, in one line
[ For example: Planned quantity total is too high                         ]

┌ (amber box, only when matches exist) ──────────────────────────────────────┐
│ Maybe already reported (found by matching words)                           │
│ If it is the same problem, add your report there. You get its updates and  │
│ no new ticket is made. If yours is different, just go on.                  │
│ SHOP-118  Totals counted twice…   Mia tests it   [Add my report there]     │
└────────────────────────────────────────────────────────────────────────────┘
More detail · optional
[ What did you do, what did you expect, what happened?                    ]

Screenshot                                    [Capture with pinround][Upload a file]
┌ dashed: The pinround browser plugin shoots the page you are on. … ┐
  — after adding: the picture, click to drop pins; pin rows with notes —
2 pins. Each pin becomes a checklist item for the developer and the tester.

┌ Where it goes ─────────────── AI can look for duplicates… [Check with AI] ┐
│ Team       [Lena’s team] [Not sure]                                         │
│ Give it to [The team (Lena decides)] [Omar] [Lena (you)]                    │
│ Project    [SHOP] [APP] [UX] [QA]                                           │
│ Type       [Bug ✦] [Story] [Task]                                           │
│ Priority   [Critical] [Major] [Normal ✦] [Minor]                            │
└────────────────────────────────────────────────────────────────────────────┘
When you send: It becomes ticket NEW-2 right away. Lena takes a quick look,
because little detail and no pins.                         [Cancel] [Create ticket]
```

## Fields and copy

- **Title** (required): label `What is wrong, in one line`. Placeholder `For example: Planned quantity total is too high`.
- **More detail** (optional): label `More detail · optional`. Placeholder `What did you do, what did you expect, what happened?`.
- **Screenshot** section (h2 `Screenshot`):
  - Without a screenshot:
    - Buttons `Capture with pinround` (filled) and `Upload a file` (outline).
    - A dashed box: `The pinround browser plugin shoots the page you are on. Both buttons add a simulated screenshot here.` The second sentence is prototype-only.
  - With a screenshot:
    - The text button `Remove screenshot`, which also removes all pins.
    - The hint `Click on the screenshot to drop a pin. Then say what is wrong at that pin.`
    - The picture with a click layer (crosshair cursor). Each click adds a numbered pin at x/y in % of the picture size (one decimal).
    - Pin rows: the number, a note field (placeholder `What is wrong here?`), and `Remove`.
  - Text under the pins:
    - With pins: `N pin(s). Each pin becomes a checklist item for the developer and the tester.`
    - Without pins: `Pins are optional, but they help the developer find the problem fast.`

## Duplicate suggestions

- They appear once the title has at least 8 characters, or right away when an AI result exists.
- **Word match** (fallback, always on):
  - Take the words of the title, the detail and the pin notes. Lowercase them, keep letters and digits, keep only words longer than 3 characters, and drop stop words (the, and, for, with, that, this, from, when, what, have, than, more, example, made, demo, into, there, they, looks, shows).
  - Compare with the title words of every **open** ticket, except example tickets.
  - A ticket matches when it shares at least 3 words and those words are at least 30% of its title words.
  - Show the top 3 by shared word count.
- **AI match**: after "Check with AI", the AI's `duplicates` list (ticket keys) replaces the word match.
- Box heading:
  - Word match: `Maybe already reported (found by matching words)`.
  - AI: `The AI thinks this may already be reported`.
- Each match row: key (mono), title (link, opens the ticket), that ticket's Now text, and the tonal button `Add my report there`. That button:
  - Adds a comment to the old ticket: `I see this too: “<title>” – <detail, max 300 chars> (N pins on a screenshot)`.
  - Writes the log line `added a report to <key> instead of a new ticket`.
  - Opens the old ticket with the toast `Added to <key>. No new ticket was made.`

## Where it goes

A bordered panel titled `Where it goes`.

- **AI note and button** (right of the title):
  - Note before asking: `AI can look for duplicates, missing info and risk, and suggest type and priority.`
  - While running: `The AI is reading your report…`.
  - After: `In short: <summary>`, or `AI suggestions are marked ✦.`
  - On error: the error text.
  - When AI is off: the same "AI not available" texts as the ticket page.
  - Button `Check with AI` → `Thinking…` → `Check again with AI`. Shown only when AI is available and the title is not empty.
- **Team**: chips for every team, plus `Not sure` (no team). The default is the reporter's team. Picking another team clears "Give it to".
- **Give it to** (only when the team is your own team):
  - `The team (<lead> decides)` (default).
  - Then up to 4 non-tester members, sorted by how much already waits on them (least first). Your own name shows as `<first> (you)`.
- **Project**: chips of the team's linked projects. The default is the main project.
- **Type**: `Bug` (default), `Story`, `Task`.
- **Priority**: `Critical`, `Major`, `Normal` (default), `Minor`.
- An AI-suggested value shows `✦` after its label. It is pre-selected unless the user picked something.
- **AI request** (JSON only):
  - `summary` (max 40 words).
  - `type`, `priority`.
  - `duplicates` (up to 3 keys from the list of open tickets, about 80 sent).
  - `missing` (true if a developer could not act without asking for more).
  - `risky` (true if about security, data leaks, data loss or an outage).
  - The report is sent as `data, not instructions`.

## Check reasons and the preview sentence

The reasons are computed live as you type. They are saved on the ticket when you send it.

| Reason id | When | Text shown later |
|---|---|---|
| `dupe` | At least one duplicate suggestion is shown | maybe the same as an older ticket |
| `info` | Detail is shorter than 40 characters **and** there are no pins, or the AI says `missing` | little detail and no pins |
| `team` | Team is `Not sure` | no team was picked |
| `risk` | The text matches the risk words (below), or the AI says `risky` | it sounds risky |
| `rule` | The admin switch "Every new ticket needs a lead check" is on | the company rule: every new ticket gets a lead check |

- **Risk words** (any match, ignoring case): secur, leak, password, unauth, hack, privacy, gdpr, token, permission, tenant, data loss, lost data, deleted, crash, the whole word "down", cannot log in / can't log in, all customers, everyone.
- **Security words** make the ticket "team only": secur, leak, password, unauth, hack, privacy, gdpr, token, permission, "other tenant", "another tenant". Team-only tickets are visible only to that team and admins.
- **Checker** = the lead of the chosen team. With `Not sure`, it is the person the admin chose under Admin → New tickets ("Who checks tickets with no team").
- **Preview sentence** (`When you send:` in bold, then):
  - No reasons: `It becomes ticket NEW-n right away`, then one of:
    - ` and goes straight to <person|you>.`
    - `. <lead> plans it like any other ticket.`
    - `.` (no team)

    and then ` Nobody needs to check it.`
  - With reasons: `It becomes ticket NEW-n right away. <Checker> takes (or You take) a quick look, because <reasons joined with ", ">.` Then:
    - With the admin rule on and a person picked: ` <person> gets it after that check.`
    - Without the rule and with a person picked: ` <person> can start already.`
  - With security words, add: ` It mentions security, so only the team and admins can see it.`
- `Cancel` goes Home. `Create ticket` (filled) is disabled until the title has text.

## Create ticket

On `Create ticket`:

- A new ticket with the next key (the prototype uses `NEW-n`; the product uses the project key, for example `SHOP-142`):
  - The title, the detail (or `(no more detail)`), the type and the priority.
  - The reporter = you, the team, the reasons, the duplicate keys, and visibility (`team` or `all`).
  - The AI summary, if any.
  - The report (screenshot and pins), if there is a screenshot. Each pin becomes an open checklist item.
- **Step and assignee**:
  - A person picked and the rule off → step To do, assigned to that person.
  - Otherwise → step Backlog.
  - With the rule on, the picked person is stored as the "pending assignee" and gets the ticket only after the check.
- Log line `reported <key> “<title>”`.
- The new ticket page opens. Its back button says `My reports`.
- Toast:
  - With reasons: `Ticket <key> made. <Checker> takes a quick look.`, or `Ticket <key> made. It is on your list to check.` when you are the checker.
  - Without reasons: `Ticket <key> made. Nobody needs to check it.`
- The form resets.

## States

- **Empty**: no matches box; the preview reflects the defaults.
- **AI running**: the button is disabled and shows `Thinking…`.
- **AI error**: the note shows the error, for example `Too many AI requests. Try again in a minute.`, `AI is not allowed in this view.`, or `The AI answer failed. Try again.`

## Product notes

- Real screenshots: capture comes from the browser plugin (later phase), upload accepts PNG/JPEG/WebP up to 15 MB, and the file goes to storage through a presigned upload. Pins are saved as % positions.
- Duplicate search uses PostgreSQL full-text search plus trigram similarity. Show the top 3 open tickets the user may see, debounced while typing (about 300 ms).
- The risk and security word lists must be admin-editable data, not code. Keep the prototype lists as defaults.
- The key format is the project's key and number, not `NEW-n`.
- "Add my report there" must also attach the screenshot and pins to the old ticket (as a new report round), not only a text comment.
