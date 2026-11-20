# demo-shop

A tiny Acme Foods shop with four planted bugs, to try pinround on. It is not part of pinround.

```bash
make demo-shop                 # from the repo root: http://localhost:8090
cd demo-shop && npm test       # 4 tests fail while the bugs are in
```

Node 20 or newer, no packages. **Reset demo data** (top right) brings the shop back to its start.

| Page | What is on it |
|---|---|
| `/products` | Products, a search box, **Add to cart** |
| `/cart` | Quantities, a **Discount code** box (`WELCOME10` works), the total |
| `/checkout` | Name, email, password, **Place order** |
| `/orders` | Orders and a **Status** filter |

The answers are in [BUGS.md](BUGS.md). After Claude Code fixed them, put them back with `git restore demo-shop/src`.
