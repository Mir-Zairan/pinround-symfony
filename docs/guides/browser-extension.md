# The pinround browser extension

How to install, connect and use the Chrome extension that captures the page you are on into pinround.

## Index
- [What it does](#what-it-does) — where a capture can go, and what it never does
- [Install it](#install-it) — load the folder, find its id
- [Let pinround accept it](#let-pinround-accept-it) — the two origin settings an admin sets once
- [Connect it](#connect-it) — make a token and paste it
- [Capture a page](#capture-a-page) — a new report, or your draft round on a ticket
- [Notebooks](#notebooks) — make one, pin a page and capture it there
- [Ask Claude Code about a notebook](#ask-claude-code-about-a-notebook) — the `explain_my_notebook` prompt and its token
- [Play scenarios](#play-scenarios) — Play, Step, Stop, Next and Back, and what stops a run
- [Allowed hosts for scenarios](#allowed-hosts-for-scenarios) — where scenarios may play, and the question before a run
- [Safety](#safety) — what the player never does, and who decides
- [Sign out](#sign-out) — the token stops working everywhere
- [Permissions and why](#permissions-and-why) — the three Chrome permissions it asks for
- [Manual check](#manual-check) — the capture and notebook checks to run by hand, and the record of the last runs

## What it does
- **A new report.** Capture one or more pages of the app you test, each with an optional title and a line on what it shows. The first capture makes a private report draft in pinround and each later one adds to it. Press **Done** and pinround opens the draft for you. You check it and press **Send**; only then is there a ticket ([ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md)).
- **Your draft round on a ticket.** Pick a ticket where it is your turn or that is assigned to you. Each capture goes into your private draft round on it ([ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md)). Nobody else sees it until you share it.
- **A notebook.** Pin your own app's pages into a private notebook, ask Claude Code about the pins, and play the scenarios it saves there ([Notebooks](#notebooks)).
- It never makes a ticket and never sends a report. A capture never reads the page's text: it takes a picture of the visible part of the tab when you click.
- It talks only to the pinround address you set. The picture itself goes straight to pinround's storage, to the one upload address pinround gave for it.

## Install it
The extension is not in the Chrome Web Store yet. Load it from the repository:
1. Open `chrome://extensions` and turn on **Developer mode** (top right).
2. Click **Load unpacked** and pick the `extension/` folder.
3. Note the extension's **ID** on its card, for example `abcdefghijklmnopabcdefghijklmnop`. Its origin is `chrome-extension://` followed by that ID.

The ID stays the same as long as the folder is loaded from the same path on that computer. Chrome 120 or newer.

## Let pinround accept it
A browser sends the extension's origin with every request. pinround and its storage accept only the origins they are told, so an admin of the pinround server sets both once:
- `EXTENSION_ORIGINS` for the app: the extension's origin, for example `chrome-extension://abcdefghijklmnopabcdefghijklmnop`. Several are comma-separated. Without it every call from the extension gets 403 ([config.md](../operations/config.md)).
- The storage's CORS origins: in the dev stack, `MINIO_CORS_ALLOW_ORIGIN` in `.env.local`, as a comma-separated list with the app origin first, for example `https://localhost,chrome-extension://abcdefghijklmnopabcdefghijklmnop`; then run `docker compose up -d --force-recreate minio-init`. In production, add the origin to the bucket's CORS rule for `PUT`. Without it the picture upload fails with "The screenshot could not be stored."

## Connect it
1. In pinround, open **API tokens** under your avatar and make a token. Tick `rounds:write` to add screenshots to your draft rounds, `reports:write` to make report drafts, and `notebooks:write` ("Let Claude Code and the extension write to your notebooks.") to make notebooks and capture into them. `read` is needed too, for your ticket list, your notebooks and their scenarios.
2. Right-click the extension's icon and choose **Options**.
3. Type the pinround address you open in the browser, for example `https://pinround.acme-foods.example` (plain `http://` only for `localhost`).
4. Paste the token and press **Save and check**. The extension makes one call to pinround and keeps the token only when pinround accepts it. It is kept in this browser profile only, never synced.

## Capture a page
1. Open the page with the problem and click the extension's icon.
2. In **Put the screenshot in**, choose **A new report** or **My draft round on SHOP-12**.
3. For a new report, type the project key (for example `SHOP`) and what is wrong in one line.
4. Optionally give the capture a title (up to 120 characters) and say what the picture shows (up to 600).
5. Press **Capture this page**. For a draft round the picture is added at once; pinround processes it first, which can take a few seconds.
6. For a new report, open the next page and capture again, up to 10 pictures; each goes into the same report draft. Then press **Done**. pinround opens the draft: check it and press **Send**.

## Notebooks
A notebook is a private set of screenshots of your own app, each with numbered pins on the spots you want to ask about. Only you see it. You find yours in pinround under **My notebooks** (`/notebooks`).
1. Click the extension's icon. In **Put the screenshot in**, choose **A new notebook**, type a **Notebook title** (up to 120 characters) and press **Make the notebook**. The popup says "Notebook made. Captures now go to it."
2. To add to a notebook you have, choose **Notebook: <title>** instead. The list shows the 20 notebooks you used last.
3. Open the page you want to ask about and press **Pin this page**. Click the page to drop a pin, and type in the box that opens what you want to know about that spot; **Save note** closes the box. Click again for the next pin. Without a mouse, move with the arrow keys and press Enter. Escape closes the overlay and drops the pins.
4. Click the extension's icon again and press **Capture this page**. The picture goes to the notebook with its pins: "Screenshot 2 added to the notebook. Only you see it."
5. A notebook holds at most 10 screenshots, and a picture at most 100 pins, each note up to 1,000 characters.

The extension remembers your choice until the browser closes. With a notebook chosen, the popup also shows its [scenarios](#play-scenarios).

## Ask Claude Code about a notebook
Claude Code reads your notebook and answers each pin from the code it can see. When you ask for cases, it writes scenarios the extension can play.
1. Connect Claude Code to pinround, as [claude-code.md](claude-code.md) says, with a token that has `read` and `notebooks:write`. Reading needs `read`; saving scenarios needs `notebooks:write`, which a new token does not start with.
2. In Claude Code, type `/` and pick pinround's `explain_my_notebook` prompt. With the server named `pinround` it is `/mcp__pinround__explain_my_notebook`. It takes no arguments.
3. Name the notebook, for example "Explain my notebook Checkout totals." Claude Code answers pin by pin: "Screenshot 1, Pin 1", then "Screenshot 1, Pin 2", and so on.
4. To get cases, ask for them, for example "Show me how to make the total hide." Claude Code tells you what data each case needs (its setup) and how it will make that data in your app. It makes it once you agree, never in pinround.
5. Claude Code saves the scenarios with `save_scenarios`. A save replaces every scenario of the notebook. The format is in [reporting-with-claude-code.md › Scenarios](reporting-with-claude-code.md#scenarios).

The prompt's text is in [explain_my_notebook.v1.md](../../src/AgentApi/Infrastructure/Prompt/explain_my_notebook.v1.md).

## Play scenarios
A scenario is a short list of steps: open a page, click, type, choose, wait, check a text, pause or capture. The extension plays it in your tab while you watch.
1. Add the scenario's host to your [allowed hosts](#allowed-hosts-for-scenarios) first.
2. Open a page of that host in the tab, usually the scenario's start page.
3. Click the extension's icon and choose the notebook in **Put the screenshot in**. **Scenarios** lists its scenarios, then shows the current one: "Scenario 1 of 3", its title, **Why:** (what in the code makes it happen) and **Setup:** (the data it needs). A notebook without scenarios says "This notebook has no scenarios yet."
4. Use the buttons:
   - **Play** runs the steps one after the other until the scenario ends, stops or pauses. Each step's element is highlighted on the page, and its line shows in the popup.
   - **Step** runs the next step only: "Step done. Press Step for the next one."
   - **Stop** stops the run before the next step; the step running now ends first: "Stopped. Play to go on from this step."
   - **Next** and **Back** go to the next or the previous scenario, from its first step.
   - At the end the popup says "The scenario ran to its end." and **Play** becomes **Play again**.
5. Before the first run on a host, the popup asks once. See [Allowed hosts for scenarios](#allowed-hosts-for-scenarios).

**What stops a run.** The popup tells you why, and no later step runs:
- A check fails. The popup shows **Expected** and **Found**, for example "The page does not show the expected text."
- A step finds no element, or more than one: "Found no visible element …".
- A pause step: "Paused. Press Play to go on." Do what its line says, then press **Play**.
- A step that types into a password field, a page on a host you have not said yes to, or a closed tab. See [Safety](#safety).
- The popup closes. A run lives in the popup, and Chrome closes the popup when you click the page. Do not click the page while a scenario plays. A capture under way then stores nothing.

A capture step adds a screenshot of the page to the notebook, titled with the scenario's title.

## Allowed hosts for scenarios
A notebook's scenarios click and type on a page, so they play only on hosts you list. Nothing is allowed at first.
- **The list.** In the options, under **Allowed hosts**, type a host in **Host to add** and press **Add**, for example `http://localhost:8000` or `https://shop.acme-foods.test`. Type the scheme, host and port only: no path, no user name. Only the port may be `*`. **Add http://localhost:\*** adds that entry with one press; it is hidden once the entry is listed.
- **At most 50 entries.** Past that, the page says "Your list holds 50 hosts, the most it can. Remove one first."
- **Never pinround.** A scenario never plays on the pinround address you connected to, also when the list allows it: "Scenarios never play on pinround itself." A host is its scheme, host and port, so the same host on another port is not pinround.
- **One question per host.** Before a run, the popup asks once about each host the run needs that you have not answered yet: "This scenario clicks and types on shop.acme-foods.test. It can change data there." Press **Play anyway** to run; the extension keeps the answer, so the next run there asks nothing. **Cancel** runs nothing.
- **Remove** next to an entry takes it off the list and forgets your answers for every host the list no longer allows, so a later run there asks again. **Sign out** forgets all answers; the list stays.
- **A click that leaves.** If a click takes the page to a host you have not said yes to, the run stops before the next step: "Stopped: this page is on bakery.acme-foods.test, which you have not said yes to." Nothing runs there. A host that is not on the list stops the run too.

## Safety
- **Allowed hosts only.** A scenario plays only on a host on your list, after you said **Play anyway** for it once ([Allowed hosts for scenarios](#allowed-hosts-for-scenarios)). A host off the list: "shop.acme-foods.test is not on your allowed hosts. Add it in the extension options, then play again."
- **Never pinround.** "Scenarios never play on pinround itself."
- **Never a password field.** The player does not type into one: "The player never types into a password field. Type it yourself, then play on." It does not read one either: "The player never reads a password field. Look at it yourself, then play on."
- **AI suggests, you play.** Claude Code only writes scenarios into your notebook. Nothing runs until you press **Play** or **Step**, and nothing runs on a new host until you press **Play anyway**.
- **Data, never code.** A scenario is a list of steps the player reads. A step finds its element by role and name, by visible text or by label, never by a CSS or XPath string. The extension holds no `eval(` or `new Function`, and the build fails if one appears.
- **Text from pinround is shown as text.** Titles, lines and notes never become part of the page or the popup's markup.
- **A closed tab stops a run.** "The tab is closed. Open the scenario's page and play again." Nothing runs in another tab in its place.

## Sign out
In the options, press **Sign out**. The extension asks pinround to revoke the token, so it stops working everywhere, then forgets it, with your "Play anyway" answers. If pinround cannot be reached, the extension still forgets it and tells you to revoke it on the **API tokens** page.

## Permissions and why
| Permission | Why |
|---|---|
| `activeTab` | To take a picture of the tab you are on, only after you click the extension's icon. It gives no access to other tabs and none in the background. |
| `scripting` | To run the extension's own pin overlay and scenario player in the tab you are on, only after you click the extension's icon. It never runs a script from the network. |
| `storage` | To keep the pinround address, your token, your allowed hosts and your "Play anyway" answers in this browser profile, and, until the browser closes, where your captures go and the report draft they are adding to. |

It asks for no host permission and has no content scripts. It injects only its own files, and only into the tab you chose by clicking its icon. `extension/tests/pairing.test.mjs` checks that the manifest asks for exactly these.

## Manual check
Run by hand on the dev stack after a change to the extension:
1. Load the extension, set `EXTENSION_ORIGINS` and `MINIO_CORS_ALLOW_ORIGIN` as above, and connect with a token that has `read`, `rounds:write` and `reports:write`.
2. Capture two pages as a new report for `SHOP`, press **Done**, check that pinround opens the draft with both pictures (after "Processing…"), and press **Send**: the ticket has Round 0 with both pictures.
3. As the assignee of a ticket, capture into **My draft round on** it: the picture is in your draft round, and the tester does not see it.
4. Sign out, then try to capture: the popup asks you to connect first, and the old token gets 401 from `GET /api/v1/me/tickets`.

Last run: not run yet.

The notebook check, run by a person who did not build it, following only this guide and [reporting-with-claude-code.md › Scenarios](reporting-with-claude-code.md#scenarios):
1. Connect with a token that has `read` and `notebooks:write`, and allow the host of a test app.
2. Make a notebook, pin a page of the test app with two notes, and capture it.
3. In Claude Code, run `/mcp__pinround__explain_my_notebook` on that notebook: it answers both pins. Ask for cases: it saves at least two scenarios.
4. In the popup, play two scenarios to their end, with **Next** between them.

Last run: not run yet. Write the date, who ran it, the client and model, and any step that failed here.
