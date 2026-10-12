# Clear Four Small Backlog Items Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Each task's backlog file is the spec for that task; read it before starting.

**Status:** active
**Goal:** Close four mechanical backlog items from the 2026-10-12 docs-sync audit in one PR into `dev/edd`.
**Spec:** none, planned from the request. Each task links its backlog item.
**Architecture:** Each backlog item gets one task and one commit. A task applies the backlog's suggested fix, checks its `## Done when` condition, then deletes the backlog file, since every box is then ticked. No shared code changes: `format.helper.ts` is only consumed.
**Touches:** `scripts/run.sh`, `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`, `apps/admin/src/pages/dashboard/dashboard-page.notice-copy.ts`, `apps/admin/src/pages/dashboard/components/DashboardAnomalySettlements.tsx`, `apps/admin/src/pages/settings/general-settings.form.ts`, `apps/admin/src/lib/schema/setup.schema.ts`, `apps/admin/src/pro/rewards/archive/RewardArchiveDialog.tsx`, `apps/admin/src/pro/campaigns/archive/CampaignArchiveDialog.tsx`, four files in `docs/backlog/`
**Tests:** none. Under CLAUDE.md's test rule none is warranted: no money or ledger logic changes, and no fixed bug needs a reproducing test.

## Requirements

- **Goal:** the four backlog items below are fixed and their files are removed.
- **Out of scope:** `components/ui/chart.tsx` and `components/ui/calendar.tsx`, which are vendored and still call `toLocaleString()`. Also out: the two 2026-10-06 backlog items (referrals, tiers) and the unrelated local edit to `composer.json`.
- **Acceptance criteria:**
  1. `cmd_clean` in `scripts/run.sh` removes `assets/dist/blocks`.
  2. `git grep -n toLocaleString -- apps/admin/src/pages` prints nothing.
  3. The `POINTS_LABEL_MAX_LENGTH` comment opens with `Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH, the source of truth.` and the `setupSchema` comment opens with `Mirrors SetupService::payload(), the source of truth.`
  4. `git grep -n "'\.\./\.\./shared/" -- apps/admin/src/pro` prints nothing.
  5. The type-check exits 0, and `ls docs/backlog` lists only the two 2026-10-06 items.
- **Decided with the user:** one PR for all four items (from the request).

## Global constraints

- Branch from `dev/edd` as `trace/chore/clear-small-backlog`, then run `git branch --unset-upstream`. The user pushes and opens the PR in GitHub Desktop.
- Commit messages: Conventional Commits `type(scope): summary`, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_01QpFTi3EMf9e79uBTwH1YEq`.
- Stage files by name. `composer.json` is modified on the working tree today (php-scoper `^0.18.20`). Leave it unstaged.
- Run checks without pnpm, so no `pnpm install` or `composer install` fires (docs/known-issues.md). From `apps/admin`, run `node node_modules/typescript/bin/tsc --noEmit` and `node node_modules/eslint/bin/eslint.js <files>`. The type-check exits 0 on `dev/edd` today. Admin lint fails on existing code, so the bar is no new ESLint error or warning in the changed files.
- Comments: keep to one line. Don't add new comments.

## Review focus

- **A comma-decimal store** (`decimal_sep` `,`, `thousand_sep` `.`): the anomaly title and threshold read `3,2×` and `3×`, and the pager reads `1–10 of 4.812 entries`. Task 2 Step 4 checks this.
- **A whole multiple** (`3.0`, the `AnomalyDetector::MULTIPLE` default): `formatStoreDecimal` trims zeros, so the text shows `3×`, not `3.0×`. Task 2 Step 4 checks this.

---

### Task 0: Branch and save the plan

- [ ] **Step 1:** Run `git switch -c trace/chore/clear-small-backlog dev/edd` and then `git branch --unset-upstream`.
- [ ] **Step 2:** Copy this plan to `docs/plans/2026-10-12-clear-small-backlog.md`.
- [ ] **Step 3: Commit**
  - Stage: `docs/plans/2026-10-12-clear-small-backlog.md`
  - Message: `docs(plans): plan clearing four small backlog items`

### Task 1: `run.sh clean` removes the blocks build

Backlog: `docs/backlog/2026-10-12-clean-leaves-the-blocks-build.md`

**Files:**
- Modify: `scripts/run.sh:420-432` (`cmd_clean`)
- Delete: `docs/backlog/2026-10-12-clean-leaves-the-blocks-build.md`

- [ ] **Step 1:** In `cmd_clean`, add a third `if [ -d "$PLUGIN_DIR/assets/dist/blocks" ]` branch after the storefront branch. Copy the storefront branch's form: `rm -rf` the directory, then `print_success "Removed assets/dist/blocks"`.
- [ ] **Step 2: Verify**
  - Run: `mkdir -p assets/dist/blocks && touch assets/dist/blocks/editor.js && ./scripts/run.sh clean`
  - Expected: the output includes `Removed assets/dist/blocks`, and `ls assets/dist/blocks` fails with "No such file".
  - Afterwards, run `./scripts/run.sh build` to restore the dist for the local site, if the site needs it.
- [ ] **Step 3:** Delete the backlog file.
- [ ] **Step 4: Commit**
  - Stage: `scripts/run.sh`, `docs/backlog/2026-10-12-clean-leaves-the-blocks-build.md`
  - Message: `fix(scripts): remove the blocks build in run.sh clean`

### Task 2: Store separators instead of `toLocaleString()`

Backlog: `docs/backlog/2026-10-12-multiples-and-pager-counts-use-tolocalestring.md`

**Files:**
- Modify: `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts:97-106` (`pagerSummary`)
- Modify: `apps/admin/src/pages/dashboard/dashboard-page.notice-copy.ts:101`
- Modify: `apps/admin/src/pages/dashboard/components/DashboardAnomalySettlements.tsx:80`
- Delete: `docs/backlog/2026-10-12-multiples-and-pager-counts-use-tolocalestring.md`

**Interfaces:**
- Consumes `formatStoreNumber(value: number, decimals: number): string` and `formatStoreDecimal(value: number, maxDecimals: number): string` from `@/lib/helpers/format.helper`. Both are unchanged.

- [ ] **Step 1:** In `pagerSummary`, use `formatStoreNumber(from, 0)`, `formatStoreNumber(to, 0)` and `formatStoreNumber(total, 0)`. Add the `@/lib/helpers/format.helper` import.
- [ ] **Step 2:** In the anomaly title, use `formatStoreDecimal(n.data.multiple, 1)`. Add `formatStoreDecimal` to the existing `format.helper` import. One decimal matches `round( $yesterday_points / $average, 1 )` in `includes/Services/Dashboard/AnomalyDetector.php:50`.
- [ ] **Step 3:** In `DashboardAnomalySettlements`, use `formatStoreDecimal(threshold.multiple, 1)`. Add the import.
- [ ] **Step 4: Verify**
  - Run: `git grep -n toLocaleString -- apps/admin/src/pages`. Expected: no output.
  - Run the type-check and ESLint on the three files, as Global constraints describes. Expected: tsc exits 0, and ESLint shows no new errors or warnings.
  - In the browser, set WooCommerce → Settings → General to thousand separator `.` and decimal separator `,`. Open a customer drawer with more than 10 ledger rows (user 1 has about 30). Expected: the pager reads `1–10 of 30 entries · newest first`.
  - If the dashboard shows a points-jump notice, its title shows a comma decimal, and the threshold line reads `3×`. If no notice shows, check `formatStoreDecimal(3, 1)` in the browser console on the admin page. Expected: `"3"`.
  - Restore the separators afterwards.
- [ ] **Step 5:** Delete the backlog file.
- [ ] **Step 6: Commit**
  - Stage: the three source files and the backlog file
  - Message: `fix(admin): format pager counts and multiples with store separators`

### Task 3: Mirror comments name their source of truth

Backlog: `docs/backlog/2026-10-12-two-mirrors-miss-the-source-of-truth-wording.md`

**Files:**
- Modify: `apps/admin/src/pages/settings/general-settings.form.ts:5`
- Modify: `apps/admin/src/lib/schema/setup.schema.ts:3`
- Delete: `docs/backlog/2026-10-12-two-mirrors-miss-the-source-of-truth-wording.md`

- [ ] **Step 1:** Change line 5 of `general-settings.form.ts` to `/** Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH, the source of truth. */`.
- [ ] **Step 2:** Change line 3 of `setup.schema.ts` to `/** Mirrors SetupService::payload(), the source of truth. POST /setup takes it and returns the saved values. */`.
- [ ] **Step 3: Verify**
  - Run: `git grep -n 'the source of truth' -- apps/admin/src/pages/settings/general-settings.form.ts apps/admin/src/lib/schema/setup.schema.ts`
  - Expected: two lines that match the wording in Steps 1 and 2.
- [ ] **Step 4:** Delete the backlog file.
- [ ] **Step 5: Commit**
  - Stage: both source files and the backlog file
  - Message: `docs(admin): name the source of truth in two PHP mirrors`

### Task 4: Import the archive dialog through `@/pro/shared`

Backlog: `docs/backlog/2026-10-12-archive-dialog-imported-by-relative-path.md`

**Files:**
- Modify: `apps/admin/src/pro/rewards/archive/RewardArchiveDialog.tsx:5`
- Modify: `apps/admin/src/pro/campaigns/archive/CampaignArchiveDialog.tsx:25`
- Delete: `docs/backlog/2026-10-12-archive-dialog-imported-by-relative-path.md`

- [ ] **Step 1:** In both files, change `'../../shared/ArchiveOrDeleteDialog'` to `'@/pro/shared/ArchiveOrDeleteDialog'`. If the import-order rule asks for it, move the import into the `@/` group.
- [ ] **Step 2: Verify**
  - Run: `git grep -n "'\.\./\.\./shared/" -- apps/admin/src/pro`. Expected: no output.
  - Run the type-check and ESLint on both files. Expected: tsc exits 0, and ESLint shows no new errors or warnings.
- [ ] **Step 3:** Delete the backlog file.
- [ ] **Step 4: Commit**
  - Stage: both source files and the backlog file
  - Message: `refactor(admin): import the shared archive dialog through @/pro`

### Task 5: Final check and close the plan

- [ ] **Step 1:** Run the whole-SPA type-check from `apps/admin`: `node node_modules/typescript/bin/tsc --noEmit`. Expected: exit 0.
- [ ] **Step 2:** Run `ls docs/backlog`. Expected: only `2026-10-06-referrals-not-built.md` and `2026-10-06-tiers-not-built.md`.
- [ ] **Step 3:** Run `git status`. Expected: only `composer.json` is modified and unstaged.
- [ ] **Step 4:** Set this plan's Status to `done 2026-10-12`. Commit with message `docs(plans): mark clear-small-backlog done`. Then hand over the branch name and a PR description ending with `🤖 Generated with [Claude Code](https://claude.com/claude-code)` and the session link.
