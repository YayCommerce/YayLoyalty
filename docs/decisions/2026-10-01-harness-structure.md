# Harness structure: a short index, scoped rules and docs reached by link

**Purpose:** why this repo's agent and developer harness (`CLAUDE.md`, `.claude/`, `docs/` and `README.md`) has the shape it has. It records the decisions behind the harness renewal.

**Update when:** never edit it. Supersede it with a new record when the harness's shape changes.

## Status

Accepted, 2026-10-01. Trace Bui approved these decisions while planning the renewal, and answered its open questions in the plan's P1 review. The IDs in brackets are the plan's: [the harness renewal plan](../plans/2026-10-01-harness-renewal.md) also holds the evidence for each one. On 2026-10-02, its skills decision ([A4, A8, Q1]) was superseded by [Three shared skills take work from design to plan to review](2026-10-02-shared-design-plan-review-skills.md). Its other decisions stand.

## Context

- The previous harness was a large doc set that every session read up front. It restated the code (trees, class names, counts, status), so it drifted, and many code commits had to edit it too.
- Code comments cited doc paths and section numbers, which broke silently whenever a doc changed. Feature IDs were defined only in the roadmap and the changelog.
- `.gitignore` hid `docs/plans/` and every new file under `.claude/` from git, and the one team skill had a name that Claude Code doesn't register.
- Claude Code loads `CLAUDE.md` and unscoped rules at launch, `@` imports included, so whatever they hold costs context in every session. A path-scoped rule loads only when Claude reads a file it matches.
- The old harness is preserved at the tag `harness-v1-final`.

## Decision

**What the harness is**

- [A1] `CLAUDE.md`, `.claude/`, `docs/` and `README.md` make up the harness. The module READMEs under `includes/Engine/`, the docs-layout check script and the AI block in `.gitignore` belonged to the old harness and are removed.
- [A6] Claude Code is the only agent supported, so there is no `AGENTS.md`.
- [A2] Designs for unbuilt features don't stay in the tree. Code says "designed, not built yet", and a new spec is written when the feature is built.
- [A9] No feature IDs, roadmap or development changelog. A to-do names its topic, as in `TODO(redemption)`, and release notes stay in `readme.txt`.

**Its shape**

- [A5, Δ1] `CLAUDE.md` is an index under 100 lines: commands, hard rules, git rules and where to look. It reaches docs through plain links with "read when…" triggers, never `@` imports. A doc that Claude keeps missing may be promoted to an import, by a new record.
- [A10, Δ5, Q2] Path-scoped rules in `.claude/rules/`, one each for PHP, the admin SPA and the release tooling, with globs that match this repo's real paths. The PHP rule covers `includes/`, `tests/`, the PHP scripts and the root plugin files, `YayLoyaltyPluginAdapter.php` included.
- Each topic has one owner, and the others link to it: rules in `CLAUDE.md` or `.claude/rules/`, the mechanism in `docs/architecture.md`, conventions in `docs/conventions.md`, gotchas in `docs/known-issues.md`, reasons in `docs/decisions/`, and onboarding and upkeep in `README.md`.
- [Δ2] `README.md` is the front door for people: onboarding, the daily workflow, the agent tooling and how to keep the harness current.
- Every doc, rule and skill opens with Purpose and Update when. Docs cite code by path and symbol, and code never cites a doc, a section number or a feature ID. Nothing outside `docs/plans/` carries status, progress or counts.
- [A12] Plans and specs are kept as history, each with a Status line that agents check before acting.

**Tooling**

- [A7, Δ4] A committed `.claude/settings.json` holds an allowlist for the routine checks and `git diff` and `git log`, a hook that blocks edits to generated directories, and `includeGitInstructions: false`, with Claude Code's default commit attribution kept. That setting also removes Claude Code's built-in git guidance, so `CLAUDE.md` states the git rules itself.
- [Δ3] `.gitignore` ignores personal Claude files, and nothing else of the harness.
- [A4, A8, Q1] The team's own skills are `brainstorming` and `writing-plans`, written from scratch, and there are no others. The old duplicate-rule checker is removed for good, with its readable-code rules.
- [Q5] `.nvmrc` pins Node 22, and the root `package.json` declares it, because pnpm 11 fails on older Node.
- [Q6] Every teammate runs Claude Code 2.1.283 or later, for `/doctor prompt-audit`.
- [Q9] The lockfiles stay gitignored, and `docs/known-issues.md` records the cost.

**Workflow**

- [A11, Q7] `dev/edd` is the long-lived base. Every change, docs included, reaches it through a PR.
- [Q4] Trace Bui reviews each phase PR of the renewal. Someone who didn't build the harness does its final validation.
- [A3] The removal phase counts as green when nothing regresses: the same counts and exit codes as before. Failures that already existed are recorded in `docs/known-issues.md`, not fixed.
- [Q8] Trace announces a docs freeze at least a day before the removal merges, and pushes the tag.
- [Q3] These existing app decisions get backfilled records: the [build-time Pro/Lite split](2026-10-01-build-time-pro-lite-split.md), [`LedgerService` as the only ledger writer](2026-10-01-ledger-service-sole-writer.md), [admin-shell scoped with php-scoper](2026-10-01-admin-shell-scoped-with-php-scoper.md), [UTC storage](2026-10-01-store-datetimes-in-utc.md) and [money as decimal strings](2026-10-01-money-as-decimal-strings.md).

## Consequences

- A session starts from a short index. A scoped rule loads when Claude reads a file it matches, and a doc when its "read when…" trigger fires. If Claude misses a doc it needs, the promotion rule above applies.
- Docs cite code and code never cites docs, so a code change doesn't break a doc reference, and a doc edit never touches code. Facts the code shows aren't written down: read the code for them.
- With the built-in git guidance off, the git rules in `CLAUDE.md` are the only ones, so they have to stay complete.
- The generated-directory hook sees edits made with Claude's edit tools, not writes made through the shell.
- With untracked lockfiles, a documented command can still behave differently on another machine.
- Old designs and the old docs' history are read at the tag, not in the tree.
