---
name: pinround-setup
description: Set up a local pinround for a person from zero — the dev stack and demo people, Node's trust in the local HTTPS certificate, Claude Code's pinround MCP connection in the folder of the app they test, and the Chrome extension. Use when someone asks to "set up pinround", "connect Claude Code to pinround", "install the pinround extension", or hits a pinround setup error (UNABLE_TO_GET_ISSUER_CERT_LOCALLY, 401 from /mcp, 403 from the extension, "The screenshot could not be stored.").
argument-hint: "[folder of the app to test, for example ~/pinround-demo-target]"
---

# pinround setup

Takes a person from nothing to a working pinround: web app, Claude Code tools and browser extension. The script does every machine step; the person does four browser steps that need them (two-step login, tokens, loading the extension, its options).

`S=.claude/skills/pinround-setup/pinround-setup.sh` (run from the repo root). Run `$S` alone for its usage.

## Rules
- **Never ask for, print or paste a token.** The person runs the `mcp-command` lines in their own terminal, not with `!` here: a token typed into this chat stays in the transcript. Those lines read the token with `read -rs`, so it is not on screen, in the shell history or on a command line. Never claim more than that.
- **Never assume a Claude Code profile.** Most people start plain `claude` (settings in `~/.claude.json`). Some set `CLAUDE_CONFIG_DIR` for a second profile. Ask how they start Claude Code only when `mcp-status` shows pinround in a profile they do not use.
- Never print `DEMO_PASSWORD`. Tell the person to read it with `grep DEMO_PASSWORD .env.dev.local`.
- Never run plain `docker compose` for this stack: it skips `.env.local`. Use `make`, or the script.
- Ask before you edit `~/.bashrc` or any file outside this repo.
- After each step, run `$S check` and go on only when that step shows `ok`.

## Steps
1. **Check.** Run `$S check` and show the person the MISSING lines in plain words.
2. **Stack and people.** Run `$S stack` in the background when the app is not ready (a first `make up` takes 5–6 minutes). It writes a random `DEMO_PASSWORD` to `.env.dev.local` when there is none and runs `make seed`.
   The demo people all use that password: `omar@acme-foods.example` (developer), `mia@` (tester), `lena@` (lead), `sara@` (admin), `raj@` (manager). Project `SHOP` of team Shop already exists: use it for the app under test.
3. **Certificate.** Run `$S trust-ca`. It must end with `Node with it: HTTP 200`. Ask whether to add `export NODE_EXTRA_CA_CERTS="$HOME/.config/pinround/caddy-root.crt"` to their shell startup file (`~/.bashrc`, or `~/.zshrc` for zsh); without it, they start Claude Code with that variable in front. `check` counts the startup file, so this session, started before the edit, still shows `ok`.
4. **Person, in the browser** (give these exact steps, then wait):
   - Open the app address from `$S check`, accept the certificate warning, log in as the person they will act as (usually Omar).
   - Turn on two-step login at `/me/security/two-step` with any authenticator app. Tokens need it.
   - At `/me/tokens`, make **two** tokens, each with `read`, `rounds:write`, `reports:write` and `notebooks:write` ticked: "Claude Code" and "Chrome extension". Two, because **Sign out** in the extension revokes its token everywhere.
5. **Claude Code.** Ask for the folder of the app they test (the skill argument, if given). Run `$S mcp-command <folder>` and give the person those lines as they are, to run in the terminal where they start Claude Code. At `pinround token:` they paste the "Claude Code" token and press Enter; nothing shows.
   Then run `$S mcp-status <folder>`. It checks `~/.claude.json` and, when this session sets `CLAUDE_CONFIG_DIR`, that profile too. The profile they start Claude Code with must say the token works. pinround in another profile only is not a failure: ask which one they start, and if it is not there, have them set `CLAUDE_CONFIG_DIR` the same way and run the lines again.
6. **Extension.** The person opens `chrome://extensions`, turns on **Developer mode**, presses **Load unpacked**, picks this repo's `extension/` folder and gives you the 32-letter ID from its card. Run `$S extension <id>`; both lines must say `ok`.
7. **Person, extension options** (right-click the icon › **Options**): address = the app address, paste the "Chrome extension" token, **Save and check**; under **Allowed hosts**, add the app under test, for example `http://localhost:8090`.
8. **First test.** The person restarts Claude Code in the app folder (with `NODE_EXTRA_CA_CERTS` set) and asks "Use pinround's whoami tool." Then the first journey in [docs/guides/claude-code.md](../../../docs/guides/claude-code.md) and [docs/guides/browser-extension.md](../../../docs/guides/browser-extension.md#capture-a-page).

## Known traps
| What the person sees | Cause | Fix |
|---|---|---|
| `UNABLE_TO_GET_ISSUER_CERT_LOCALLY` in `/mcp` | Node does not trust Caddy's local certificate | Step 3, then restart Claude Code |
| `No MCP server named "pinround"` / `already exists in local config` | Each `CLAUDE_CONFIG_DIR` profile keeps its own settings, and `claude mcp add` stops on an existing name | Run the `mcp-command` lines (they remove first) in the shell that starts Claude Code; `mcp-status` shows which profile has it |
| Extension gets 403 | `.env.dev` (tracked) sets another ID and wins over `.env.local` in dev | `$S extension <id>` writes it to `.env.dev.local` |
| "The screenshot could not be stored." | Storage CORS does not list the extension | `$S extension <id>` |
| MinIO fails on port 9001 "address already in use" | Plain `docker compose` skipped `.env.local` and its ports | Recreate with the script or `make` |
| Home says "All clear" with tickets on the Tickets page | Home content is phase P07, not built yet | Use **Tickets** and **Whose turn?** |
| Token once typed on a command line | It may be in the shell history | Revoke it at `/me/tokens` and make a new one |
| A changed setting has no effect | Worker mode keeps the old config | `$S extension` restarts; otherwise `docker compose restart app worker worker-heavy` |
