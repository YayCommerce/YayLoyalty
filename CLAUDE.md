# YayLoyalty

Read `README.md` first, then `docs/code-standards.md` for layering, naming and database rules.

Comments: docblocks open with one line saying what it does for the caller, then only the *why*. Inline comments default to none — code shows *how*; comment only to carry *why* (constraint, deviation, gotcha, workaround). Full rule in `docs/code-standards.md` § 15 Comments.

Before committing a feature, run `/yay:code-corrector`; rule owners live in `docs/code-standards.md` § 16 Single source of truth.
