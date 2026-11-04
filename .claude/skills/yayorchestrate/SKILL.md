---
name: yayorchestrate
description: Runs one release action (status, build, release, release-fix) for many YayCommerce plugin editions at once, across repos: one pinned admin-shell revision, one background yayrelease-worker per edition in its own build tree, one consolidated review the user approves per row, then serialized pushes after confirmation and a combined HANDOFF grouped by target. Never uploads or tags. Run only when the user invokes /yayorchestrate.
disable-model-invocation: true
# Purpose: parallel builds/releases without shared state: one pin, one worker per edition, one review.
# Update when: the run folder, task file, report shape or serialized finish changes.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/Yay-Release-Kit .claude/skills/yayorchestrate/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YayOrchestrate

`YR` = `.claude/release-kit/bin/yr`. Usage: `/yayorchestrate <status|build|release|release-fix> <plugin:edition> [<plugin:edition> …] [--pin=<admin-shell tag>] [--dry-run]`. Plugins come from `~/.config/yayrelease/registry.json`, so they may live in other repos.

## 1. Plan (shared preparation)

1. `status` is read-only: run `YR status --all` (or per plugin) and stop. No workers needed.
2. `YR orchestrate plan --action=<a> --targets=<p:e,…> [--pin=<ref>] [--dry-run]`. It fetches each repo once, pins **one** shared-code revision for the whole run (`pins.json`), preflights every row, groups branch-group editions for release actions, and writes `~/.yayrelease/runs/<run-id>/` (RUN.json, PLAN.md, tasks/*.json).
3. Show PLAN.md. Point out: the pin and any warning on it (e.g. the tag's code reports another VERSION); rows `skipped`/`blocked` and why (they never block the others); for `release`, each row's proposed version.
   With `--dry-run`, stop here.
4. Fill the task files (each worker gets only its file):
   - `release`: ask once, for all rows together, which test build was approved per row (default: each row's latest) → `approved_test_build` (its BUILD.json path); and any version override → `version`.
   - `release-fix`: the store feedback per row → `feedback`, and where X.Y.Z is live → `live_somewhere` (true/false).
   Then ask for one go-ahead.

## 2. Fan out

- For each worker in RUN.json `workers`, batch by batch (`batch` = 1, 2, …; batch size = the concurrency limit, default 3, because composer/pnpm builds are heavy): spawn a background **yayrelease-worker** agent per worker with the prompt: `Task file: <run>/tasks/<id>.json. Kit: <that repo>/.claude/release-kit/bin/yr (fall back to <this repo>/.claude/release-kit/bin/yr --repo=<repo> if the repo has no kit).` Wait for the batch to finish (you are notified; don't poll), then start the next.
- Workers never ask; anything needing a decision is in their report. A worker that fails or is blocked does not stop the others.

## 3. Consolidated review

1. `YR orchestrate collect --run=<run-id>` → `REVIEW.md`: one table (plugin, edition, status, version change, gates, shared code, warnings), conflicts (rows on different shared-code revisions, siblings left behind under a shared version policy), each blocked/failed reason, every open question, and all changelog drafts.
2. Show it and ask the user to decide per row in one pass: approve / edit (changelog text, version) / override / drop.
   - **Different shared-code revisions across rows** (only possible when a row was built before this run): releasing them together must be an explicit, approved decision; say why (the highest admin-shell version wins at runtime on sites with several YayCommerce plugins).
   - `blocked: needs-shared-change`: the shared change is made once, in the admin-shell repo, released, then those rows are re-run with a new pin. Not inside this run.
3. Re-run only the rows that need changes: update their task file (`changelog_draft` = path of the edited draft JSON, `version`, …) and spawn a worker for just those rows. Then collect again.

## 4. Serialized finish (you, one row at a time)

For each approved row of a `release` / `release-fix` run, in order:
1. If the row's draft was edited and the worker was not re-run, do that now (a changed readme.txt changelog means a new zip).
2. `YR git push-plan --repo=<repo> --refs=<release branch>`; show it; push with `YR git push --repo=<repo> --refs=<b> --confirmed` after the user's yes. (Workers committed locally only.)
3. Ask whether the zip goes to the manager now; on yes, `YR builds mark-submitted --repo=<repo> --build-json=<…>`.

Then: `YR orchestrate handoff --run=<run-id> --builds=<the approved BUILD.json paths>` → `HANDOFF-<date>.md` grouped by target, linking each edition's HANDOFF.md. Print where it is. Remind: Woo feedback → `/yayorchestrate release-fix …` or `/yayrelease-fix`; live → `/yayfinalize` (one plugin at a time).

For a `build` run, the finish is just the review table plus each row's tester note (from the reports) and the zip paths.

## Failure handling

- A worker crashed without a report: its row shows "no report yet"; inspect its worktree (`YR worktree list`), re-run that row alone.
- The pin cannot be resolved: stop before fan-out; nothing has been built.
- Never push, tag or merge on behalf of a worker before the review approval.
