# Clear Four Small Backlog Items Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Each task's backlog file is the spec for that task; read it before starting.

**Status:** done 2026-10-12
**Goal:** Close four mechanical backlog items from the 2026-10-12 docs-sync audit in one PR into `dev/edd`.
**Spec:** none, planned from the request. Each task links its backlog item.
**Architecture:** Each backlog item gets one task and one commit. A task applies the backlog's suggested fix, checks its `## Done when` condition, then deletes the backlog file, since every box is then ticked. No shared code changes: `format.helper.ts` is only consumed.
**Touches:** `scripts/run.sh`, `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`, `apps/admin/src/pages/dashboard/dashboard-page.notice-copy.ts`, `apps/admin/src/pages/dashboard/components/DashboardAnomalySettlements.tsx`, `apps/admin/src/pages/settings/general-settings.form.ts`, `apps/admin/src/lib/schema/setup.schema.ts`, `apps/admin/src/pro/rewards/archive/RewardArchiveDialog.tsx`, `apps/admin/src/pro/campaigns/archive/CampaignArchiveDialog.tsx`, four files in `docs/backlog/`, and the per-symbol mirror comments in `apps/admin/src/lib/` (Task 6)
**Tests:** none. Under CLAUDE.md's test rule none is warranted: no money or ledger logic changes, and no fixed bug needs a reproducing test.

## Requirements

- **Goal:** the four backlog items below are fixed and their files are removed.
- **Out of scope:** `components/ui/chart.tsx` and `components/ui/calendar.tsx`, which are vendored and still call `toLocaleString()`. Also out: the two 2026-10-06 backlog items (referrals, tiers) and the unrelated local edit to `composer.json`.
- **Acceptance criteria:**
  1. `cmd_clean` in `scripts/run.sh` removes `assets/dist/blocks`, by removing all of `assets/dist`.
  2. `git grep -n toLocaleString -- apps/admin/src/pages` prints nothing.
  3. The `POINTS_LABEL_MAX_LENGTH` comment opens with `Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH, the source of truth.` and the `setupSchema` comment opens with `Mirrors SetupService::payload(), the source of truth.`
  4. `git grep -n "'\.\./\.\./shared/" -- apps/admin/src/pro` prints nothing.
  5. The type-check exits 0, and `ls docs/backlog` lists only the two 2026-10-06 items.
  6. Every TypeScript comment that mirrors a PHP symbol opens with `Mirrors <PHP symbol>, the source of truth.`
- **Decided with the user:** one PR for all four items (from the request). After the yaytidy pass, the user asked to fix the mirror wording everywhere in this phase (Task 6).

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

- [x] **Step 1:** Run `git switch -c trace/chore/clear-small-backlog dev/edd` and then `git branch --unset-upstream`.
- [x] **Step 2:** Copy this plan to `docs/plans/2026-10-12-clear-small-backlog.md`.
- [x] **Step 3: Commit**
  - Stage: `docs/plans/2026-10-12-clear-small-backlog.md`
  - Message: `docs(plans): plan clearing four small backlog items`

### Task 1: `run.sh clean` removes the blocks build

Backlog: `docs/backlog/2026-10-12-clean-leaves-the-blocks-build.md`

**Files:**
- Modify: `scripts/run.sh:420-432` (`cmd_clean`)
- Delete: `docs/backlog/2026-10-12-clean-leaves-the-blocks-build.md`

- [x] **Step 1:** In `cmd_clean`, replace the per-folder `admin` and `storefront` branches with one `if [ -d "$PLUGIN_DIR/assets/dist" ]` branch that runs `rm -rf` on it, then `print_success "Removed assets/dist"`. Nothing in `assets/dist` is tracked (`.gitignore` ignores `/assets/dist/*`), and only the three builds write there, so a future build output can't be missed again.
- [x] **Step 2: Verify**
  - Run: `mkdir -p assets/dist/blocks && touch assets/dist/blocks/editor.js && ./scripts/run.sh clean`
  - Expected: the output includes `Removed assets/dist`, and `ls assets/dist` fails with "No such file". Run it on a scratch copy holding `scripts/run.sh` and a stub `yayloyalty.php`, because a real run deletes the local site's built assets.
  - Afterwards, run `./scripts/run.sh build` to restore the dist for the local site, if the site needs it.
- [x] **Step 3:** Delete the backlog file.
- [x] **Step 4: Commit**
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
- Produces `formatMultiple(value: number): string` in `apps/admin/src/pages/dashboard/dashboard-page.notice-copy.ts`, which returns `formatStoreDecimal(value, 1)`. Its comment reads `Mirrors AnomalyDetector::evaluate()'s one-decimal rounding, the source of truth.`

- [x] **Step 1:** In `pagerSummary`, use `formatStoreNumber(from, 0)`, `formatStoreNumber(to, 0)` and `formatStoreNumber(total, 0)`. Add the `@/lib/helpers/format.helper` import.
- [x] **Step 2:** Add `formatMultiple`, and use `formatMultiple(n.data.multiple)` in the anomaly title. Add `formatStoreDecimal` to the existing `format.helper` import. One decimal matches `round( $yesterday_points / $average, 1 )` in `includes/Services/Dashboard/AnomalyDetector.php:50`.
- [x] **Step 3:** In `DashboardAnomalySettlements`, use `formatMultiple(threshold.multiple)`, imported from `'../dashboard-page.notice-copy'`.
- [x] **Step 4: Verify**
  - Run: `git grep -n toLocaleString -- apps/admin/src/pages`. Expected: no output.
  - Run the type-check and ESLint on the three files, as Global constraints describes. Expected: tsc exits 0, and ESLint shows no new errors or warnings.
  - In the browser, set WooCommerce → Settings → General to thousand separator `.` and decimal separator `,`. Open a customer drawer with more than 10 ledger rows (user 1 has about 30). Expected: the pager reads `1–10 of 30 entries · newest first`.
  - If the dashboard shows a points-jump notice, its title shows a comma decimal, and the threshold line reads `3×`. If no notice shows, check `formatStoreDecimal(3, 1)` in the browser console on the admin page. Expected: `"3"`.
  - Restore the separators afterwards.
- [x] **Step 5:** Delete the backlog file.
- [x] **Step 6: Commit**
  - Stage: the three source files and the backlog file
  - Message: `fix(admin): format pager counts and multiples with store separators`

### Task 3: Mirror comments name their source of truth

Backlog: `docs/backlog/2026-10-12-two-mirrors-miss-the-source-of-truth-wording.md`

**Files:**
- Modify: `apps/admin/src/pages/settings/general-settings.form.ts:5`
- Modify: `apps/admin/src/lib/schema/setup.schema.ts:3`
- Delete: `docs/backlog/2026-10-12-two-mirrors-miss-the-source-of-truth-wording.md`

- [x] **Step 1:** Change line 5 of `general-settings.form.ts` to `/** Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH, the source of truth. */`.
- [x] **Step 2:** Change line 3 of `setup.schema.ts` to `/** Mirrors SetupService::payload(), the source of truth. POST /setup takes it and returns the saved values. */`.
- [x] **Step 3: Verify**
  - Run: `git grep -n 'the source of truth' -- apps/admin/src/pages/settings/general-settings.form.ts apps/admin/src/lib/schema/setup.schema.ts`
  - Expected: two lines that match the wording in Steps 1 and 2.
- [x] **Step 4:** Delete the backlog file.
- [x] **Step 5: Commit**
  - Stage: both source files and the backlog file
  - Message: `docs(admin): name the source of truth in two PHP mirrors`

### Task 4: Import the archive dialog through `@/pro/shared`

Backlog: `docs/backlog/2026-10-12-archive-dialog-imported-by-relative-path.md`

**Files:**
- Modify: `apps/admin/src/pro/rewards/archive/RewardArchiveDialog.tsx:5`
- Modify: `apps/admin/src/pro/campaigns/archive/CampaignArchiveDialog.tsx:25`
- Delete: `docs/backlog/2026-10-12-archive-dialog-imported-by-relative-path.md`

- [x] **Step 1:** In both files, change `'../../shared/ArchiveOrDeleteDialog'` to `'@/pro/shared/ArchiveOrDeleteDialog'`. If the import-order rule asks for it, move the import into the `@/` group.
- [x] **Step 2: Verify**
  - Run: `git grep -n "'\.\./\.\./shared/" -- apps/admin/src/pro`. Expected: no output.
  - Run the type-check and ESLint on both files. Expected: tsc exits 0, and ESLint shows no new errors or warnings.
- [x] **Step 3:** Delete the backlog file.
- [x] **Step 4: Commit**
  - Stage: both source files and the backlog file
  - Message: `refactor(admin): import the shared archive dialog through @/pro`

### Task 5: Final check and close the plan

- [x] **Step 1:** Run the whole-SPA type-check from `apps/admin`: `node node_modules/typescript/bin/tsc --noEmit`. Expected: exit 0.
- [x] **Step 2:** Run `ls docs/backlog`. Expected: only `2026-10-06-referrals-not-built.md` and `2026-10-06-tiers-not-built.md`.
- [x] **Step 3:** Run `git status`. Expected: only `composer.json` is modified and unstaged.
- [x] **Step 4:** Set this plan's Status to `done 2026-10-12`. Commit with message `docs(plans): mark clear-small-backlog done`. Then hand over the branch name and a PR description ending with `🤖 Generated with [Claude Code](https://claude.com/claude-code)` and the session link.

### Task 6: Every PHP mirror uses the convention's wording

Added after the yaytidy pass. Task 3 fixed the two mirrors the backlog listed. 23 per-symbol mirror comments still put `Mirrors` mid-comment, ended it with `;` or `:`, or said "which owns it" (docs/conventions.md, "TypeScript copies of PHP name their owner").

**Files:**
- Modify: `apps/admin/src/lib/api/design.api.ts`, `apps/admin/src/lib/points-math/points-math.ts`, and in `apps/admin/src/lib/schema/`: `campaign-performance.schema.ts`, `campaign.schema.ts`, `customer.schema.ts`, `dashboard.schema.ts`, `design.schema.ts`, `ledger.schema.ts`, `lookup.schema.ts`, `reward.schema.ts`

- [x] **Step 1:** Reword each comment to open with `Mirrors <PHP symbol>, the source of truth.`, keeping the rest of its text. `dashboard.schema.ts`'s notice keys name `DashboardNotices`, which holds them as literals. Leave `components/ui/chart.tsx` and `pro/campaigns/CampaignTable.tsx`, which don't mirror PHP.
- [x] **Step 2: Verify**
  - Run: `git grep -n -i 'mirrors\|which owns' -- apps/admin/src | grep -v 'the source of truth'`. Expected: only `chart.tsx:15`, `CampaignTable.tsx:546` and `reward.schema.ts:147`, whose wording wraps onto line 148.
  - Each PHP symbol named still exists under `includes/`.
  - The type-check and ESLint on the changed files: exit 0. Prettier shows no new warnings. `ledger.schema.ts` is already flagged on `dev/edd`, for its `LEDGER_TYPES` line.
- [x] **Step 3: Commit**
  - Stage: the ten files and this plan
  - Message: `docs(admin): name the source of truth in every PHP mirror`
