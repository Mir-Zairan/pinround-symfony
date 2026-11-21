# pinround

**A symfony of visibility between humans and AI.**

AI builds in minutes what used to take days. The person who has to check it first, often the developer who asked for it, cannot test that much by hand. pinround is a web app where AI shows what it built as screenshots on a ticket. People pin and note what is wrong on them, AI reads the notes, fixes the code and adds the next try, and everyone sees Before and After. Developers check their own work before handing it over, testers add the edge cases they find as the next round, and every ticket says whose turn it is.

## Index
- [Why pinround](#why-pinround): what each ticket shows, and for whom
- [How it works](#how-it-works): the five steps from screenshot to verdict
- [Built with](#built-with): Symfony AI, MCP, Symfony 8.1, PostgreSQL, the extension
- [See it in action on the demo shop](#see-it-in-action-on-the-demo-shop): setup with or without Claude Code, demo people
- [Try the loop](#try-the-loop): report, fix, hand over and test one bug
- [What pinround does today](#what-pinround-does-today): the features built so far
- [Coming next](#coming-next): the next phases
- [Good to know](#good-to-know): sample data, start and stop, guides
- [Working on pinround](#working-on-pinround): where to start in the repo
- [License](#license): MIT

## Why pinround
Every fix and every feature has its own ticket, and the ticket shows what was built for it.
- **See what was built.** The screenshots of this fix or feature sit on its ticket, Before and After, with pins on what is still wrong.
- **Know what to check.** Each round shows the reviewer and the tester which screenshots to look at, and they mark each one.
- **See what AI did.** If you build with AI, you see its work as pictures, not only as code, so you can tell quickly whether it is what you asked for.
- **Everyone sees the same rounds.** pinround is a ticketing app: the developer, reviewer, tester, lead and manager all see the rounds and whose turn it is.

## How it works
1. **AI shows what it built.** While Claude Code builds or fixes, it takes screenshots and uploads them to the ticket in pinround, into your private draft round. Testers' captures from the browser extension land there too.
2. **You pin what is wrong.** Put numbered pins on a screenshot, each with a note, and mark it **Looks right** or **Not right yet**.
3. **AI fixes from your notes.** Claude Code reads the pins, fixes the code and adds a new try of each screenshot. Repeat until it looks right.
4. **Hand it over with proof.** Share the draft as a round. The reviewer or tester sees each screenshot Before and After.
5. **Testers add what they find.** They capture edge cases from the browser extension, or ask their own Claude Code to check, and share them as the next round. The developer fixes them the same way.

AI only suggests and uploads. A person decides every verdict, and a report becomes a ticket only when a person presses **Send**.

## Built with
- **Symfony AI** (`symfony/ai-platform`): **Summarize with AI** and **Check with AI**, through Hugging Face (the default), Anthropic, or Ollama on the person's laptop.
- **MCP** (`mcp/sdk` with `symfony/mcp-bundle`): pinround's own MCP server at `/mcp`, with 18 tools and 3 prompts for Claude Code, and sign-in with OAuth 2.1 and PKCE.
- **Symfony 8.1** on PHP 8.4+ and FrankenPHP in worker mode: Symfony UX (Turbo, Stimulus, Live Components), AssetMapper with no JavaScript build, Messenger workers, Mercure live updates.
- **PostgreSQL 18** with row-level security for each organization, Doctrine ORM, S3-compatible storage (MinIO) with presigned uploads.
- **Chrome extension** in plain JavaScript (Manifest V3).
- **Checks:** PHPUnit, Playwright, PHPStan at max level, Deptrac module rules, PHP CS Fixer and Rector.

## See it in action on the demo shop
The repo bundles `demo-shop/`, a small shop with 4 planted bugs ([answers](demo-shop/BUGS.md)). You need Docker with Compose v2, `make`, `git`, Node 22+, Chrome and [Claude Code](https://docs.claude.com/en/docs/claude-code/overview).

```bash
git clone https://github.com/Mir-Zairan/pinround-symfony.git && cd pinround-symfony
```

**With Claude Code (about 15 minutes):** run `claude`, type `/pinround-setup` and follow it. It runs every machine step and stops only for what you must click.

**Without Claude Code:** run the same steps by hand. `S=.claude/skills/pinround-setup/pinround-setup.sh`
1. `$S stack`: starts pinround and loads the demo people (first run: 5–6 minutes).
2. `$S demo-mode on`: skips two-step login on this laptop, so you need no authenticator app.
3. `$S trust-ca`: lets Claude Code trust pinround's local HTTPS certificate.
4. In Chrome, open `chrome://extensions`, turn on **Developer mode**, **Load unpacked** › pick `extension/`. Then run `$S extension <its 32-letter id>`.
5. In the extension's options: **Connect to pinround** with `https://localhost`, log in as Mia, **Allow**. Under **Allowed hosts**, add `http://localhost:8090`.
6. `make demo-shop`, then open http://localhost:8090.
7. Connect Claude Code (needed for the AI steps):
   ```bash
   cd demo-shop
   claude mcp add --transport http pinround https://localhost/mcp
   NODE_EXTRA_CA_CERTS=~/.config/pinround/caddy-root.crt claude
   ```
   In Claude Code, type `/mcp`, pick `pinround` › authenticate, log in as Omar, **Allow**.

`$S check` says what is ready and what is missing. Open pinround at https://localhost.

| Person | Email | Role |
|---|---|---|
| Mia | `mia@acme-foods.example` | tester |
| Lena | `lena@acme-foods.example` | lead |
| Omar | `omar@acme-foods.example` | developer |
| Sara | `sara@acme-foods.example` | admin |
| Raj | `raj@acme-foods.example` | manager |

All use one password: `grep DEMO_PASSWORD .env.dev.local`

## Try the loop
**1. Report a problem**, as Mia. On the shop, add the same product twice: the total is wrong.
- **Extension:** click it › **A new report** › project `SHOP` › **Capture this page** › **Done**. In the draft pinround opens, pin the wrong total and press **Send**.
- **Claude Code:** run `/mcp__pinround__report_while_investigating` and say "Check the cart total at http://localhost:8090". It keeps the evidence and files a report draft; you press **Send**. This needs a token with `reports:write` ([how](docs/guides/claude-code.md#1-make-a-token)).

**2. Give it to the developer.** As Lena, open the ticket and **Give it to** Omar.

**3. Fix it and check it yourself**, as Omar. Press **Start work**. In `demo-shop`, ask Claude Code: "Read my pinround notes for SHOP-<number>, fix it, and add an after-screenshot to my draft round." (Claude Code needs a browser tool to take screenshots.) Pin anything still wrong and mark it **Not right yet**, then run `/mcp__pinround__fix_my_round_notes SHOP-<number>`: each fix comes back as the next try. Mark it **Looks right**. `npm test` in `demo-shop` now passes for that bug.

**4. Hand it over.** Press **Send to code review** › **Share and move**. Lena sees the round with Before and After and sets her own verdicts.

**5. Test and add the next round**, as Mia, once it is her turn. Capture edge cases into her draft round on the ticket (extension › **Put the screenshot in** › **My draft round on SHOP-<number>**), or ask her Claude Code to check and add them there. She shares them as the next round, and Omar fixes them as in step 3.

Put the bugs back with `git restore demo-shop/src`.

## What pinround does today
- **Screenshots with pins:** from the web form, the extension (with a floating dock on the page) or Claude Code; up to 5 tries per screenshot; Before and After; full-screen review.
- **Rounds:** a private draft round to check your own work, shared rounds with a verdict per screenshot, one open round per ticket.
- **Whose turn:** every ticket says "Now: …, Next: …" in plain words. **Tickets** with search and filters, a **Whose turn?** view and **My reports**.
- **Claude Code (MCP):** 18 tools and 3 prompts to read tickets and round notes, add screenshots and tries, set verdicts, file report drafts and keep notebooks. Connect by signing in or with a token.
- **pinround's own AI** (Symfony AI; Hugging Face, Anthropic or Ollama on your laptop): **Summarize with AI** on a ticket, **Check with AI** on **New ticket**.
- **Teamwork:** comments, questions that pause a ticket, pings with a bell, live page updates, duplicate suggestions on report.
- **Extension notebooks and scenarios:** pin pages of your app, ask Claude Code about them, and play back the test steps it writes.
- **Safe by default:** each organization is isolated in the database, two-step login, permission checks on every action, a phone layout.

## Coming next
- **Home** that ranks what needs you, with manager and admin views (P07).
- **Jira** import and two-way sync, and company login with SSO (P10).
- **Full admin:** roles and invites, steps and rules, activity log, retention, export (P11).
- **More AI:** "Explain it to me", summaries on sync, usage budgets, MCP tools to comment and move a ticket (P08, P09).
- **Launch hardening:** load tests, security and accessibility audits, backups (P12).

## Good to know
- **Sample data:** setup adds about 40 sample `SHOP` tickets in every step, so each page has something to show. Your own report gets the next number.
- **Home is mostly empty** until P07 is built. Your tickets are under **Tickets**.
- **Start or stop later:** `make up` / `make down`. A port is taken? Set another in `.env.local`, for example `HTTPS_PORT=8443`.
- **Guides:** [Claude Code](docs/guides/claude-code.md) · [Reporting with Claude Code](docs/guides/reporting-with-claude-code.md) · [Browser extension](docs/guides/browser-extension.md)

## Working on pinround
Read [CLAUDE.md](CLAUDE.md) first. Docs start at [docs/README.md](docs/README.md), the build plan is in [docs/05-plan](docs/05-plan). `make test` runs the tests, `make qa` every quality gate. Never put real company, customer or people data here; use Acme Foods, project SHOP, Lena, Omar, Mia, Raj and Sara.

## License
pinround, the demo shop and the extension are released under the [MIT License](LICENSE). The Atkinson Hyperlegible fonts in `assets/fonts/` keep their own [SIL Open Font License](assets/fonts/OFL.txt).
