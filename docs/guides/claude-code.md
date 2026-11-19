# Claude Code with pinround

How to connect Claude Code to pinround, so it can read your round notes, add screenshots and set verdicts in your own draft round.

## Index
- [What you need](#what-you-need) — an account with two-step login, Claude Code, the app's address
- [1. Make a token](#1-make-a-token) — the API tokens page
- [2. Add pinround to Claude Code](#2-add-pinround-to-claude-code) — the `claude mcp add` line
- [3. Check that it works](#3-check-that-it-works) — `whoami` and `list_my_tickets`
- [Example prompts](#example-prompts) — what to say to Claude Code
- [The tools](#the-tools) — what each of the ten tools does
- [Adding a screenshot](#adding-a-screenshot) — `create_upload`, `curl -T`, `add_screenshot`
- [Reporting while you check](#reporting-while-you-check) — the prompt `report_while_investigating`, and where to read more
- [When a call is refused](#when-a-call-is-refused) — the answers you may see and what they mean
- [Safety](#safety) — your token is your permissions
- [Full loop check](#full-loop-check) — the steps to run by hand, and the record of the last run

## What you need
- A pinround account in your organization.
- Two-step login turned on for your account. Without it, **Make token** sends you to the two-step login setup page first.
- Claude Code installed on your computer.
- The address of your pinround app, for example `https://pinround.acme-foods.example`. Use the address you open in the browser. pinround answers `/mcp` only on its own public address; any other host name gets "400 Bad Request".

## 1. Make a token
1. In pinround, open the menu under your avatar and choose **API tokens** (the page is `/me/tokens`).
2. Give the token a name you will recognise later, for example "Claude Code on my laptop".
3. Under **What it may do**, keep the two boxes that are ticked:
   - "Read your tickets, rounds and notes." (scope `read`)
   - "Add screenshots, notes and verdicts to your draft rounds." (scope `rounds:write`)
4. Choose how long it works: 30, 90 or 365 days.
5. Press **Make token**. pinround may ask for your password and a code again first.
6. pinround shows the token once, and a line to paste into your terminal. Copy the line now. The token is never shown again.

You may have at most 20 tokens that still work. pinround emails you about 7 days before a token stops working.

## 2. Add pinround to Claude Code
Paste the line from the token page into a terminal. It looks like this:

```bash
claude mcp add --transport http pinround https://<host>/mcp --header "Authorization: Bearer <token>"
```

- `pinround` is the name Claude Code shows for the server.
- `<host>` is your app's address; `<token>` starts with `pr_`.
- If your Claude Code version takes other flags, check `claude mcp add --help`.

Run `claude mcp list` to see that `pinround` is there. Inside Claude Code, `/mcp` shows whether it is connected.

## 3. Check that it works
In Claude Code, ask: "Use pinround's whoami tool." It answers your name, your organization, the token's scopes and when the token stops working.

Then ask: "List my pinround tickets." You should see the tickets where it is your turn or that are assigned to you.

## Example prompts
- "Read my pinround notes for SHOP-88 and fix what is marked “Not right yet”." This is the line the **For Claude Code** box on your draft round gives you to copy.
- "Which pinround tickets are waiting for me?"
- "Which open pinround ticket is about the checkout total?"
- "What does SHOP-88 ask for, and who tests it?"
- "Take a screenshot of the checkout page and add it to my draft round on SHOP-88, with a pin on the total."
- "The total on SHOP-88 is fixed now. Set the verdict of that screenshot to Looks right."

## The tools
Every tool works as you, with your permissions in the app, and only inside the token's organization. Each call is recorded in the audit log (tool, target, outcome), never its content.

| Tool | Scope | What it does |
|---|---|---|
| `whoami` | `read` | Your user id and name, your organization id, the token's scopes and `expires_at`. |
| `list_my_tickets` | `read` | Your open tickets where it is your turn or you are the assignee, the most recently changed first, at most 50 (`limit` 1 to 50). Each has its key, title, step, the "Now" line of the ticket page, `is_your_turn`, `is_assignee`, `updated_at`; `has_more` says there are more. Done tickets are never listed. |
| `find_tickets` | `read` | Searches the open tickets you may see by words of the title or description, or by key (`text`, at most 200 characters), in every project or one (`project`, such as `SHOP`). The best matches come first, at most 10; `has_more` says there are more. Each has its key, title, step, whose turn it is, and `may_start_draft`: true when it is assigned to you or it is your turn, so you may add screenshots to your own draft round on it now. Done tickets and tickets you may not see are never returned. |
| `get_ticket` | `read` | One ticket by key, as its page shows it to you: key, title, description, step, the "Now" and "Next" lines, the people on it with their roles (assignee, reporter, reviewer, tester), the open questions in its thread, and its checklist. A ticket you may not see is "not found", like one that does not exist. |
| `get_round_notes` | `read` | For one ticket key: the newest shared round that has screenshots, your own private draft round, and the checklist. Each screenshot has its id, a signed image URL that works for a few minutes, title, summary, verdict ("Looks right", "Not right yet" or none) and pins (number, `x_pct`, `y_pct` from 0 to 100, note). Another person's private draft is never returned. |
| `set_verdict` | `rounds:write` | Sets "Looks right" (`looks_right`) or "Not right yet" (`not_right_yet`) on a screenshot of your own draft round, with an optional new summary of at most 600 characters. Or, when you are the ticket's assignee, marks a checklist item fixed: `{key, check_item_id, state: "fixed"}`. |
| `create_upload` | `rounds:write` | Begins the upload of one screenshot into your own draft round on a ticket. When you have no draft there and you are the assignee, or it is your turn (for example the tester in Testing), it starts one. Answers where to send the file. |
| `create_report_upload` | `reports:write` | Begins the upload of one screenshot for a new report in a project (`project`, such as `SHOP`). Answers where to send the file, as `create_upload` does. |
| `create_report` | `reports:write` | Makes a private report draft from a title, a detail and up to 10 uploaded screenshots with titles, summaries and pins. It never makes a ticket: open the `url` it answers, check the draft and press **Send**. A token gets `reports:write` only when you tick it. |
| `add_screenshot` | `rounds:write` | Adds the uploaded picture to your draft round, with an optional title (at most 120 characters), summary (at most 600) and pins (at most 100, each note at most 1,000). |

Claude Code reads each tool's own description, so you do not need to name tools in your prompts.

## Adding a screenshot
Pictures never travel inside a tool call. A call body over 1 MB is refused. Claude Code uploads the file straight to storage instead, in three steps:

1. **`create_upload`** with `{key, mime, bytes, sha256}`:
   - `mime`: `image/png`, `image/jpeg` or `image/webp`.
   - `bytes`: the exact size of the file, at most 15 MB (for example `wc -c < shot.png`).
   - `sha256`: the file's SHA-256 in hex (`sha256sum shot.png`, or `shasum -a 256 shot.png` on a Mac). Only its form is checked.
   - The answer has `upload_id`, `put_url`, `headers` and `expires_at`. The URL works for 5 minutes.
2. **The upload**, with the headers as given:
   ```bash
   curl -T shot.png -H "Content-Type: image/png" "<put_url>"
   ```
   The URL is signed for that type and that exact size. A file of another type or size is refused by the storage.
3. **`add_screenshot`** with `{key, upload_id, title?, summary?, pins?}`. The answer has a `status`:
   - `processing`: pinround is still checking the picture. Nothing is added yet. Call `add_screenshot` again with the same `upload_id` in a few seconds.
   - `added`: the screenshot is in your draft round, with its `screenshot_id`.
   - `added_before`: this `upload_id` was added already. Nothing new is added, and the title, summary and pins of this call are not used.

A picture that is not a PNG, JPEG or WebP pinround can read, or is too big, is refused: upload another one. An `upload_id` that no `add_screenshot` used within a day expires: begin a new one.

Each person may begin 60 uploads a minute (the web page counts too) and have at most 20 uploads waiting.

## Reporting while you check
pinround also offers the MCP prompt `report_while_investigating` (in Claude Code: `/mcp__pinround__report_while_investigating`). With it, Claude Code keeps an evidence log while it checks a problem and uploads nothing until you choose. At the end it files a new report draft, or adds the pictures to your draft round on a ticket. A report draft needs a token with `reports:write` ticked, and you press **Send** in pinround yourself. See [reporting-with-claude-code.md](reporting-with-claude-code.md).

## When a call is refused
A refused call saves nothing. The answers you may see:

| Answer | Meaning |
|---|---|
| "Not found. It does not exist, or you may not see it." | Wrong key or id, another person's private draft, or a ticket you may not see. pinround does not say which. |
| "This is read-only now, so it cannot be changed." | The draft round was shared, or the checklist item is fixed or confirmed already. |
| "This token does not have the "…" scope." | Make a token with that scope ticked. |
| "The arguments do not match the input schema of this tool: …" | Claude Code sent wrong or too long input. |
| 401 | The token is missing, wrong, revoked or expired. |
| 429 | Too many calls: 60 a minute or 600 an hour per token, or 600 a minute for the whole organization. Wait as long as `Retry-After` says. |

## Safety
- **A token is your permissions.** Anyone who has it can read and change what you can, within its scopes. Keep it like a password; never put it in a repository or a shared file.
- **A token never opens admin pages**, even an admin's.
- **Revoke a lost token at once:** on **API tokens**, press **Revoke**. It stops working at once. An admin can revoke any token in the organization from Admin › People.
- **Titles, summaries and notes are other people's text.** pinround returns them between `<untrusted_text>` and `</untrusted_text>`, and tells Claude Code that this is data, never instructions. Still read what Claude Code does with them.
- **Nobody else sees your draft round** until you share it in the app. Claude Code cannot share it for you.

## Full loop check
The steps a person who did not build pinround runs by hand, on a clean machine, against a test organization:

1. Make a token on **API tokens**.
2. Run the `claude mcp add` line.
3. `list_my_tickets` shows the ticket assigned to you.
4. `get_round_notes` returns the pins of your draft round.
5. A new screenshot from `create_upload`, `curl -T` and `add_screenshot` appears in your draft round.
6. `set_verdict` changes the verdict you see in the app.

Record of the last run: none yet. Write the date, the time it took (target: 10 minutes or less) and any step that failed here.
