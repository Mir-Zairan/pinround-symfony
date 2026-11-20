# pinround

Bug reports as screenshots with numbered pins. Every ticket says in plain words what happens next and whose turn it is. Claude Code reads the pins and fixes the code.

## Set it up (about 15 minutes)

You need: Docker with Compose v2, `make`, `git`, Node 22 or newer, Chrome, and [Claude Code](https://docs.claude.com/en/docs/claude-code/overview) (logged in).

```bash
git clone https://github.com/Mir-Zairan/pinround-symfony.git
cd pinround-symfony
claude
```

In Claude Code, type `/pinround-setup` and follow it. It does everything it can, and stops only for what you must click yourself.

## Try it on the demo shop

1. In a second terminal: `make demo-shop`, then open http://localhost:8090. The shop has 4 hidden bugs.
2. Find one. For example, add the same product twice: the total is wrong.
3. Report it: click the pinround extension icon › **A new report** › project `SHOP` › **Capture this page** › **Done** › **Send**.
4. In pinround (https://localhost), log in as Lena, open the new ticket and **Give it to** Omar.
5. In a terminal: `cd demo-shop && claude`, then say: "Read my pinround notes for SHOP-<number> and fix it."
6. Run `npm test` in `demo-shop`: the test for that bug now passes.

Put the bugs back with `git restore demo-shop/src`. The answers are in [demo-shop/BUGS.md](demo-shop/BUGS.md).

## Log in

| Person | Email | Role |
|---|---|---|
| Omar | `omar@acme-foods.example` | developer |
| Mia | `mia@acme-foods.example` | tester |
| Lena | `lena@acme-foods.example` | lead |
| Sara | `sara@acme-foods.example` | admin |
| Raj | `raj@acme-foods.example` | manager |

All use the same password: `grep DEMO_PASSWORD .env.dev.local`

## Good to know

- **Something is wrong?** Run `.claude/skills/pinround-setup/pinround-setup.sh check`. It names the problem and the fix.
- **Start or stop later:** `make up` / `make down`.
- **A port is taken?** Put another one in `.env.local`, for example `HTTPS_PORT=8443`, and run `make up` again.
- **Home is empty.** That page is not built yet. Your tickets are under **Tickets**.

## Working on pinround

- Read [CLAUDE.md](CLAUDE.md) first. The docs start at [docs/README.md](docs/README.md); the build plan is in [docs/05-plan](docs/05-plan).
- `make test` runs the tests, `make qa` every quality gate, `make help` every command.
- Never put real company, customer or people data in this repository. Use Acme Foods, project SHOP, Lena, Omar, Mia, Raj and Sara.
