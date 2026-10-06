# Ledger queries branch: finish the dev/edd merge onto the new harness

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is what this plan argues from.

**Status:** done 2026-10-05
**Goal:** finish merging `dev/edd` (at `2054e97`, PR #42) into `paul/improve/ledger-queries`, so the branch runs on the new harness and loses none of the facts Paul wrote into the old docs.
**Spec:** none, planned from the request
**Architecture:** the 2 conflicts are old-harness docs that Paul edited in `3e9b5ac` and `dev/edd` deleted (`UD`). They stay deleted. Neither of Paul's two edits needs a new home, because the code already holds both: the `Dates::site_day_to_utc()` owner row is that method's own docblock, and the activity tab's site-day filter is in the `ActivityRestController` class docblock. The merge commit holds only the resolution; the plan is committed after it.
**Touches:** deletes `docs/code-standards.md`, `docs/data-model/customers-and-ledger.md`; adds this plan

## Requirements

- **Goal:** `paul/improve/ledger-queries` merges cleanly into `dev/edd` on the new harness.
- **Out of scope:**
  - the ledger and activity filter behaviour from `3e9b5ac`;
  - `docs/conventions.md` § Dates and time zones (see the decision below);
  - restoring tests (the "No tests" entry in `docs/known-issues.md` still holds);
  - pushing and opening the PR: the user does that in GitHub Desktop.
- **Acceptance criteria:**
  1. `git diff --name-only --diff-filter=U` prints nothing, and neither old doc is tracked.
  2. `./scripts/check-harness.sh` prints `Harness checks passed.` and exits 0. Before the fix, every finding it reports comes from the 2 old docs.
  3. The docblock of `Dates::site_day_to_utc()` in `includes/Utils/Dates.php` still says it returns the UTC bound for site-timezone midnight of a `Y-m-d` day, with `'+1 day'` for an exclusive end. The class docblock of `ActivityRestController` still says `after`/`before` are site-timezone calendar days converted by `Dates::site_day_to_utc()`.
  4. End to end: `git diff origin/dev/edd --stat -- CLAUDE.md README.md .claude docs` lists only this plan, so the branch changes no other harness file.
- **Decided with the user (2026-10-05):**
  - Should the merge also add the drawer's `Y-m-d` filter days to `docs/conventions.md` § Dates and time zones? No: delete only.

## Global constraints

- Never commit to `dev/edd` or `main` directly. Commit messages follow Conventional Commits, `type(scope): summary`, and end with the `Co-Authored-By` trailer that `CLAUDE.md` asks for.
- Check `git status`, and stage files by name.
- Don't push. The user publishes the branch in GitHub Desktop.

## Review focus

- **No old doc comes back:** a stray `git checkout --ours` would restore an old doc that the harness check then flags. Task 1, step 1 checks it.
- **The two files changed on both sides:** `includes/Repositories/AbstractRepository.php` (Paul made `like_fragment()` `public static`; `dev/edd` reworded the class docblock) and `includes/Pro/Controllers/CampaignPerformanceRestController.php` (Paul's `daily_series()` moved to `Dates::site_day_to_utc()`; `dev/edd` reworded the class docblock). Git merged both without conflict, in separate hunks. Task 1, step 2 checks the result.

---

### Task 1: Resolve the conflicts and conclude the merge

**Files:**
- Delete: `docs/code-standards.md`, `docs/data-model/customers-and-ledger.md`

**Interfaces:**
- Produces: a merge commit whose second parent is `2054e97`, with no unmerged paths.

- [x] **Step 1: Keep dev/edd's deletion of the 2 old docs**

Run: `git rm -q docs/code-standards.md docs/data-model/customers-and-ledger.md`
Then run `git diff --name-only --diff-filter=U`. Expected: no output.
Then run `git ls-files docs | grep -E 'code-standards|data-model/'`. Expected: no output.

- [x] **Step 2: Check the two files merged from both sides**

Run: `php -l includes/Repositories/AbstractRepository.php includes/Pro/Controllers/CampaignPerformanceRestController.php`. Expected: `No syntax errors detected` for each.
Run: `git grep -n 'like_fragment(' -- includes`. Expected: the definition is `public static function like_fragment`, `CampaignRepository` and `RewardRepository` call it as `$this->like_fragment(`, and `LedgerHistoryFilters` calls it as `AbstractRepository::like_fragment(`.
Run: `git grep -n 'docs/code-standards' -- includes apps`. Expected: no output, now that `dev/edd`'s `AbstractRepository` docblock has replaced the old `§ 8` citation.

- [x] **Step 3: Check that the two moved facts are in the code**

Run: `git grep -n -A3 'UTC bound for site-timezone midnight' -- includes/Utils/Dates.php`. Expected: the `site_day_to_utc()` docblock, naming `'+1 day'` for an exclusive end.
Run: `git grep -n 'site_day_to_utc' -- includes/Controllers/ActivityRestController.php`. Expected: one match in the class docblock, saying the activity tab's `after`/`before` are site-timezone calendar days, as on the ledger tab.
If either is missing, stop and ask the user: the deleted docs were then the fact's only home.

- [x] **Step 4: Run the harness check**

Run: `./scripts/check-harness.sh`
Expected: `Harness checks passed.` and exit 0. Before step 1 it reported `removed_refs`, `cited_docs`, `links`, `headers` and `backticked_paths` findings, all from the 2 deleted docs.

- [x] **Step 5: Commit the merge**

Stage: nothing more; step 1 staged the deletions.
Message: keep Git's `Merge branch 'dev/edd' into paul/improve/ledger-queries`, drop the `# Conflicts:` comment lines, and add a one-line body: "Keep dev/edd's removal of the old harness docs; the two facts this branch added to them already live in the Dates::site_day_to_utc() and ActivityRestController docblocks." Then the trailer.

- [x] **Step 6: Close and commit the plan**

Set this plan's Status to `done 2026-10-05` and tick steps 1 to 6.
Stage: `docs/plans/2026-10-05-ledger-queries-harness-merge.md`
Message: `docs(plans): record the ledger queries branch's harness merge`

### Final verification

Run this after step 6, since `git diff` ignores the plan until it is committed. If a check fails, set Status back to `active` and fix it in a new commit.

- [x] Run `git diff origin/dev/edd --stat -- CLAUDE.md README.md .claude docs`. Expected: only `docs/plans/2026-10-05-ledger-queries-harness-merge.md`.
- [x] Run `./scripts/check-harness.sh`. Expected: exit 0.
- [x] Run `git status`. Expected: clean, with 2 commits on top of `origin/paul/improve/ledger-queries`: the merge and the plan.
