---
paths:
  - "src/**/*.php"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- Never use an expression only for its side effect: no ternary as a statement and no value-returning call used only as a guard; write a plain `if`, or a `void` guard named for what it refuses (`refuseUnlessAllowed()`). <!-- class: other-readability, source: 20261107-515b, 20261113-7b03 -->
