<!-- managed by /hygiene-learn — delete a bullet to retire that lesson -->
# Learned process lessons

- Stage the changed files with `git add <files>` before running the hygiene `commit.sh` helper; it commits only what is staged and exits 1 when nothing is. <!-- source: learned, sig: exit 1 commit.sh, 2026-11-06 -->
- After changing `docker/postgres/roles.sql`, run `make db-roles` before the integration tests; the test database keeps the old roles until you do. <!-- source: learned, sig: there was # failure:, 2026-11-07 -->
- `doctrine:migrations:diff` exits 1 with "No changes detected" when the schema already matches, which is the good result; to check for drift, run `DatabaseRolesTest` with `--filter doctrineMigrationsDiffFindsNoChanges` instead. <!-- source: learned, sig: exit 1 doctrine:migrations:diff, 2026-11-07 -->
- In tests, don't add `self::assertInstanceOf()` for a value whose type a phpdoc or return type already states; PHPStan at max fails on it as always true. <!-- source: learned, sig: 💡 because the type is coming from a phpdoc, you can turn off this, 2026-11-07 -->
- Run PHP tools only inside the app container (`make test`, `make qa`, or `docker compose exec -T app composer <script>`); the host has no PHP. If `docker compose exec` fails because the stack is down, run `make up` first. <!-- source: learned, sig: exit 1, 2026-11-07 -->
- To stop one profile service, run `docker compose --profile <name> stop <service>`, never `docker compose ... down`: `down` stops the whole dev stack, even with `--profile`. <!-- source: learned, sig: compose down stopped the dev stack, 2026-11-07 -->
