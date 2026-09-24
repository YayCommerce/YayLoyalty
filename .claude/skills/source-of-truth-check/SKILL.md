---
name: source-of-truth-check
description: Find code in a diff that re-declares a rule another place already owns (option names, settings defaults, design bounds, points math, enum vocabularies, capabilities, helpers, comments restating a rule), in the same language or across PHP↔TS, plus readability slips in added lines. Reports first, fixes only on approval. Use before committing a feature, or when asked to dedup, find duplicates, check source of truth, or find redundant code.
argument-hint: "[path | --branch | --all]"
---

# Source-of-truth check

The rule owners live in `docs/code-standards.md` § 16 Single source of truth. Read them from there every run; never keep a copy here.

**Do not edit any file until step 7's approval.** Report only.

## 1. Scope

- No argument: added lines from `git diff HEAD` (uncommitted changes, staged or not), plus every line of new files from `git ls-files --others --exclude-standard` (the diff omits untracked files).
- `--branch`: added lines from `git diff dev/edd...HEAD` plus `git diff HEAD` (everything the branch adds). On `dev/edd` itself this equals the default scope; use a path or `--all` there.
- A path: every line in that file or directory.
- `--all`: walk each row of the owner table and grep the repo for copies of its rule; skip step 3.

Skip `vendor*/`, `node_modules/`, `assets/dist/`, `release/`, `plans/`, and `.claude/`.

## 2. Load the rules

Read `docs/code-standards.md` § 16 Single source of truth (owner table) and § 17 Readable code.

## 3. Collect candidates from the scoped lines

- String literals that name stored or wired things: option names, meta keys, table slugs, REST routes, setting keys, capability names.
- Numbers or arrays that look like a bound, default or allow-list (`'max' => 24`, `'adjustment_ceiling' => 0`, `['all', 'earn', 'redeem', 'adjust', 'expire']`).
- Rule logic: validation, allow-lists, status or rounding checks, points↔money conversion, regexes.
- New functions or helpers.
- Comments or docs that explain a rule (which ledger types exist, how points round).

## 4. Find the owner

For each candidate:

1. Match it to a table row by what it decides.
2. `grep -rn` the literal, the function's distinctive expression, or the rule's wording across `includes/ apps/admin/src apps/admin/tests tests/ docs/ yayloyalty.php`. Match on literals, distinctive expressions and phrases, never on common identifiers like `id` or `key`.
3. A new function whose body computes what an existing exported function computes is a duplicate of that function, whatever its name.

## 5. Classify

- **Owner copy** — the candidate re-declares what the owner decides instead of calling or importing it. Finding.
- **Allowed mirror** — the file is listed as a mirror for that row. Check it agrees with the owner; a mismatch is drift. A new setting or token added consistently to both owner and mirror is not a finding.
- **Unrelated** — drop it.

A rule with no table row but two deciders in the code is still a finding; mark it `(no row)`.

## 6. Report

Use exactly this format. Omit empty sections. Nothing found → `No duplicates.`

```
Duplicates
- path:line → duplicates <Owner> (<kind>) → fix: <call owner | import constant | delete + pointer>
Mirror drift
- path:line ↔ <owner>: <what differs>
Readability (added lines only)
- path:line → <rule from § 17 Readable code>
```

Readability applies only to lines the diff added, and only the four rules in § 17 Readable code. With a path or `--all`, skip it: existing code is not held to those caps.

## 7. Fix on approval

Ask with `AskUserQuestion`: fix all / pick which / none. Then edit, and verify only what changed, one command at a time:

- PHP changed: `composer lint`, then `composer test`.
- Docs changed: `./scripts/check-docs.sh`.
- TS in `apps/admin` changed: `corepack pnpm --filter @yayloyalty/admin-page lint` and `corepack pnpm --filter @yayloyalty/admin-page test`, then `corepack pnpm exec tsc --noEmit` from `apps/admin`.

`composer lint`, the admin lint and tsc all fail on the unchanged tree (phpcs warnings, eslint and prettier debt, tsc type errors): run each once before editing and compare; only new errors fail.

Use Node 22 (`export PATH="$HOME/.nvm/versions/node/v22.22.2/bin:$PATH"`) before any `corepack pnpm` command.

## 8. New owner

For a `(no row)` finding, propose the row (rule · owner · allowed mirrors). Add it to the table in `docs/code-standards.md` only after the user approves.
