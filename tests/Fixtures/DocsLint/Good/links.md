# SHOP good links

## Local heading

Lena links to [a file](target.md), [an anchor](target.md#orders--returns-shop),
[the first duplicate](target.md#same-title), [the second duplicate](target.md#same-title-1),
[this page](#local-heading) and [a folder](../Good).

Mia also uses [a root link](/tests/Fixtures/DocsLint/Good/target.md#same-title),
[a link with a title](target.md "Target title") and [an angle link](<target.md#same-title-1>).

External links are skipped: [site](https://example.com/missing), [mail](mailto:omar@example.com).

Inline code is skipped: `[x](missing.md)` and ``[y](also-missing.md#nope)``.

```md
[fenced](missing.md#nope)
```
