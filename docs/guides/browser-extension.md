# The pinround browser extension

How to install, connect and use the Chrome extension that captures the page you are on into pinround.

## Index
- [What it does](#what-it-does) — two places a capture can go, and what it never does
- [Install it](#install-it) — load the folder, find its id
- [Let pinround accept it](#let-pinround-accept-it) — the two origin settings an admin sets once
- [Connect it](#connect-it) — make a token and paste it
- [Capture a page](#capture-a-page) — a new report, or your draft round on a ticket
- [Sign out](#sign-out) — the token stops working everywhere
- [Permissions and why](#permissions-and-why) — the two Chrome permissions it asks for
- [Manual check](#manual-check) — the steps to run by hand, and the record of the last run

## What it does
- **A new report.** Capture one or more pages of the app you test, each with an optional title and a line on what it shows. Then press **Make the report draft**. pinround keeps a private report draft and opens it for you. You check it and press **Send**; only then is there a ticket ([ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md)).
- **Your draft round on a ticket.** Pick a ticket where it is your turn or that is assigned to you. Each capture goes into your private draft round on it ([ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md)). Nobody else sees it until you share it.
- It never makes a ticket, never sends a report, and never reads the page's text: it takes a picture of the visible part of the tab when you click.
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
1. In pinround, open **API tokens** under your avatar and make a token. Tick `rounds:write` to add screenshots to your draft rounds, and `reports:write` to make report drafts. `read` is needed too, for your ticket list.
2. Right-click the extension's icon and choose **Options**.
3. Type the pinround address you open in the browser, for example `https://pinround.acme-foods.example` (plain `http://` only for `localhost`).
4. Paste the token and press **Save and check**. The extension makes one call to pinround and keeps the token only when pinround accepts it. It is kept in this browser profile only, never synced.

## Capture a page
1. Open the page with the problem and click the extension's icon.
2. In **Put the screenshot in**, choose **A new report** or **My draft round on SHOP-12**.
3. For a new report, type the project key (for example `SHOP`) and what is wrong in one line.
4. Optionally give the capture a title (up to 120 characters) and say what the picture shows (up to 600).
5. Press **Capture this page**. For a draft round the picture is added at once; pinround processes it first, which can take a few seconds.
6. For a new report, open the next page and capture again, up to 10 pictures. Then press **Make the report draft**. pinround opens the draft: check it and press **Send**.

## Sign out
In the options, press **Sign out**. The extension asks pinround to revoke the token, so it stops working everywhere, then forgets it. If pinround cannot be reached, the extension still forgets it and tells you to revoke it on the **API tokens** page.

## Permissions and why
| Permission | Why |
|---|---|
| `activeTab` | To take a picture of the tab you are on, only after you click the extension's icon. It gives no access to other tabs and none in the background. |
| `storage` | To keep the pinround address and your token in this browser profile, and the captures of a report you are still collecting until you make the draft. |

It asks for no host permission and injects no script into pages. `extension/tests/pairing.test.mjs` checks that the manifest asks for exactly these.

## Manual check
Run by hand on the dev stack after a change to the extension:
1. Load the extension, set `EXTENSION_ORIGINS` and `MINIO_CORS_ALLOW_ORIGIN` as above, and connect with a token that has `read`, `rounds:write` and `reports:write`.
2. Capture two pages as a new report for `SHOP`, make the draft, check that pinround opens it with both pictures (after "Processing…"), and press **Send**: the ticket has Round 0 with both pictures.
3. As the assignee of a ticket, capture into **My draft round on** it: the picture is in your draft round, and the tester does not see it.
4. Sign out, then try to capture: the popup asks you to connect first, and the old token gets 401 from `GET /api/v1/me/tickets`.

Last run: not run yet.
