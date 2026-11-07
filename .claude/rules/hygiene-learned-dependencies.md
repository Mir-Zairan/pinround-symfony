---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
  - "config/**"
  - "composer.json"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- When code uses a package's classes, list that package in `composer.json` yourself (`require` for `src/` and `config/`, `require-dev` when only tests use it); never rely on it arriving through another package. <!-- class: dependency, source: 20261107-231d, 20261107-631a, 20261106-5d8e, 20261106-b86f, 20261106-748d -->
