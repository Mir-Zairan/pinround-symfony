<!-- managed by /hygiene-learn — delete a bullet to retire that lesson -->
# Learned process lessons

- Stage the changed files with `git add <files>` before running the hygiene `commit.sh` helper; it commits only what is staged and exits 1 when nothing is. <!-- source: learned, sig: exit 1 commit.sh, 2026-11-06 -->
- After changing `docker/postgres/roles.sql`, run `make db-roles` before the integration tests; the test database keeps the old roles until you do. <!-- source: learned, sig: there was # failure:, 2026-11-07 -->
