# Drawer filters cleanup: filtered empty states, one user index, no line-cap splits

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is what this plan argues from.

**Status:** done 2026-10-05
**Goal:** close what the last `/yayplancheck` and `/simplify` runs left on `paul/improve/ledger-queries`:
- the ledger and activity tabs say "nothing matches" instead of "nothing yet" when a filter is on;
- the ledger keeps one user-leading index instead of two;
- the last repository split made for the old line cap is folded back, and no comment cites that cap.

**Spec:** none, planned from the request
**Architecture:**
- **Empty state:** `useDrawerTabQueryState()` already holds every filter value, so it derives `isFiltered`. Each tab passes that to its rows component, which picks shared filtered copy over its own empty copy. `DrawerTable` stays unchanged, because the redemptions and referrals tabs use it with no filters.
- **Index:** it needs a new name, because dbDelta can't change an existing index's columns. dbDelta adds the new index, then `Installer::run_migrations()` drops the two it replaces.
- **Line cap:** `CustomerMetaActivitySearch` is the only cap split that builds SQL outside its repository, so it alone becomes private methods. The controller-side splits stay as files and only lose the cap clause.

**Touches:**
- the drawer SPA: `customer-drawer.copy.ts`, `customer-drawer.tab-query-state.ts` (shared: `LedgerTab`, `ActivityLogTab`), `LedgerTab.tsx`, `LedgerTabRows.tsx`, `ActivityLogTab.tsx`, `ActivityLogTabRows.tsx`;
- `includes/Repositories/CustomerMetaRepository.php`, `includes/Repositories/LedgerRepository.php`; deletes `includes/Repositories/CustomerMetaActivitySearch.php`;
- `includes/Database/Schema/LedgerTable.php`, `includes/Database/Installer.php`;
- docblocks only: 8 files under `includes/Controllers/`, plus `customer-drawer.panels.copy.ts` and `design-settings.option-labels.ts`.

## Requirements

- **Goal:** the drawer's ledger and activity tabs read correctly under a filter. The ledger keeps a single user-leading index. No code cites the removed 200-line cap.
- **Out of scope:**
  - folding the controller-side splits back into their controllers (`CustomerListFormatter`, `CustomerItemFormatter`, `CustomerItemSchema`, `CustomerRouteArgs`, `LedgerQueryArgs`, and the separate redemptions, referrals and activity controllers);
  - `docs/plans/2026-10-05-ledger-queries-harness-merge.md`: a done plan is a record, and its step 2 describes the code as it was then;
  - the baseline files' timestamps: they live in the scratchpad, not the repo. This plan captures its baseline in Task 0, before any edit;
  - bumping `YAYLOYALTY_VERSION`, and adding automated tests (the "No tests" entry in `docs/known-issues.md` still holds).
- **Acceptance criteria:**
  1. With a type, a committed search, or a date range set, a tab with no matching rows shows "Nothing matches these filters" and "Try another type, a different search or a wider date range.". With none set, the ledger still shows "No points activity yet" and the activity log "Nothing recorded yet".
  2. `git grep -n CustomerMetaActivitySearch -- includes` prints nothing. `CustomerMetaRepository::paginate_activity()` returns the same totals and ids as the Task 0 baseline for every argument set, searches included.
  3. `git grep -n -i '200-line' -- includes apps` prints nothing.
  4. After `install()` re-runs, the ledger table's user-leading index is `idx_user_history_type` on (`user_id`, `id`, `type`), `idx_user_history` and `idx_user_type` are gone from it, `customer_meta`'s own `idx_user_history` is still there, and `Installer::verify()` returns `[]`.
  5. `LedgerRepository::paginate_for_user()` returns the same totals and ids as the Task 0 baseline for every argument set.
  6. End to end: in wp-admin, in one customer's drawer, the ledger tab under the Earned segment and the activity tab under a search that matches nothing both show the filtered message. Clearing the filter brings the rows back.
- **Decided with the user (2026-10-05):**
  - Which tabs get a filtered empty state? Both, with one shared copy: "Nothing matches these filters" / "Try another type, a different search or a wider date range."
  - Replace `idx_user_type (user_id,type)` with one widened index? Yes: `idx_user_history_type (user_id,id,type)` replaces both `idx_user_history` and `idx_user_type` on the ledger.
  - How far does the 200-line cleanup go? PHP and SPA comments. Only `CustomerMetaActivitySearch` is folded back.

## Global constraints

- Product PHP must run on PHP 7.4 (`CLAUDE.md` hard rule 4, `.claude/rules/php-standards.md` § PHP 7.4).
- SQL: values go through `$wpdb->prepare()`, and table and column names go in backticks through `esc_sql()`. A table's `definition()` uses `KEY name (cols)` with no space after the comma inside the column list (docblock of `AbstractTableSchema`).
- Comments: a docblock opens with one line on what the code does for the caller, then only the why. No comment cites a doc path, section number, feature ID or artboard ID.
- SPA strings go through `@wordpress/i18n` with the `yayloyalty` text domain, as flat exported functions in the copy module.
- Lint only what you changed. Use `composer lint -- <files>` for PHP. For the SPA, run eslint and prettier on the changed files directly from `apps/admin`, with `node node_modules/eslint/bin/eslint.js <files>` and `node node_modules/prettier/bin/prettier.cjs --check <files>`, so pnpm doesn't auto-install. `tsc --noEmit` already fails on base with 3 `TS2307` errors (`docs/known-issues.md`), so the expected result is those 3 and no others.
- `wp` runs from `/Users/shrief/Local-Sites/yaydevs/app/public` as `php -d "mysqli.default_socket=/Users/shrief/Library/Application Support/Local/run/DmdPSy05S/mysql/mysqld.sock" /opt/homebrew/bin/wp …`. `wp db query` fails there, so SQL goes through `$wpdb` in `wp eval`. Below, `WP` stands for that command.
- Commit messages follow Conventional Commits, `type(scope): summary`, and end with the `Co-Authored-By` trailer that `CLAUDE.md` asks for. Stage files by name. Don't push: the user publishes from GitHub Desktop.

## Review focus

- **The filter state that counts is the committed search, not the box's text.** A search that is typed but not yet committed hasn't changed the rows. If `isFiltered` reads the typed `search`, the old rows sit under a "nothing matches" message for the debounce window. Task 1, step 2 pins `isFiltered` to `querySearch`.
- **A range with only a start day is a filter.** `pageParams.after` is set from `range.from` alone, so `isFiltered` must be true when `range.from` is set and `range.to` isn't. Task 1, step 2 pins this.
- **Dropping `idx_user_history` must touch only the ledger.** `customer_meta` has its own index with the same name. `drop_index_if_exists()` is scoped by table slug, so the call must pass `'ledger'`. Task 4, step 4 checks both tables.
- **Order inside `install()`.** dbDelta runs before `run_migrations()`, so the new index exists before the old ones are dropped, and no request runs without a user-leading index. Task 4, step 4 checks the end state.
- **The activity search's escaping moves.** `CustomerMetaActivitySearch::paginate()` trusted an already-`esc_sql()`'d table name from its caller. The folded method must build the name itself from `$this->table()`. Task 2, step 3 compares search results with the baseline.

---

### Task 0: Capture the baselines

**Files:** none

- [x] **Step 1: Check the tree is clean**

Run: `git status --porcelain`. Expected: only `?? docs/plans/2026-10-05-drawer-filters-cleanup.md`.

- [x] **Step 2: Save the repository baseline**

Write `drawer-check.php` to the scratchpad:
- For users 2 and 3, it prints `paginate_for_user()`'s total and ids for each of the 7 argument sets in the earlier `ledger-check.php`.
- For users 2 and 3, it prints `paginate_activity()`'s total and ids, with `event_types` set to `ActivityEvent::ALL`, for each of: no other args; `search` set to `Manual`; `search` set to `zzz-no-match`; and `after` 2026-10-02 with `before` 2026-10-02.

Run it with `WP eval-file`, and save the output as `drawer-before.txt` before touching any source file.
Expected: 22 lines, no PHP error, and at least one activity search line with a non-zero total. If none is non-zero, pick a word from that user's activity rows (`WP eval` over `CustomerMetaRepository::paginate_activity()` with no search) and use it in place of `Manual`.

- [x] **Step 3: Save the check baselines**

Run `composer lint` on every PHP file this plan touches, then eslint and prettier on every SPA file it touches, and keep the outputs.
From `apps/admin`, run `node node_modules/typescript/bin/tsc --noEmit`. Expected: exactly the 3 known `TS2307` errors.

### Task 1: Say "nothing matches" when a filter hides every row

**Files:**
- Modify: `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`: the per-tab empty states block
- Modify: `apps/admin/src/pages/customers/drawer/customer-drawer.tab-query-state.ts`: the returned object
- Modify: `apps/admin/src/pages/customers/drawer/tabs/LedgerTabRows.tsx`, `tabs/ActivityLogTabRows.tsx`: their props and `DrawerTable` empty copy
- Modify: `apps/admin/src/pages/customers/drawer/tabs/LedgerTab.tsx`, `tabs/ActivityLogTab.tsx`: the rows component call

**Interfaces:**
- Produces:
  - `export const EMPTY_FILTERED_TITLE = () => __('Nothing matches these filters', 'yayloyalty');`
  - `export const EMPTY_FILTERED_BODY = () => __('Try another type, a different search or a wider date range.', 'yayloyalty');`
  - `useDrawerTabQueryState()` returns `isFiltered: boolean` beside its current fields.
  - `LedgerTabRows` and `ActivityLogTabRows` take `isFiltered: boolean`.

- [x] **Step 1: Add the shared copy**

Add both constants in `customer-drawer.copy.ts`, directly after `EMPTY_ACTIVITY_BODY`, with a one-line comment saying the ledger and activity tabs both show them when a filter hides every row.

- [x] **Step 2: Derive `isFiltered` in the hook**

`isFiltered` is true when `filter !== initialFilter`, or `querySearch !== ''`, or `range?.from` is set. It reads `querySearch`, the committed search, not `search`: the rows only change when the search commits (see Review focus). Add one docblock line on the field saying so.

- [x] **Step 3: Pick the copy in both rows components**

When `isFiltered` is true, each rows component passes `EMPTY_FILTERED_TITLE()` and `EMPTY_FILTERED_BODY()` to `DrawerTable`. Otherwise it passes its current empty copy.

- [x] **Step 4: Pass the flag from both tabs**

`LedgerTab` and `ActivityLogTab` pass `isFiltered={state.isFiltered}` to their rows component.

- [x] **Step 5: Lint and type-check**

From `apps/admin`, run eslint and prettier `--check` on the 6 files. Expected: no finding that the Task 0 baseline didn't have.
Run `node node_modules/typescript/bin/tsc --noEmit`. Expected: the same 3 `TS2307` errors and no others.

- [x] **Step 6: Check it in wp-admin**

Open `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty#/customers`, then the first customer's drawer, then its Points ledger tab, and select Earned.
Expected: "Nothing matches these filters" with its body.
Select All. Expected: the rows return.
Open the Activity log tab and search `zzz-no-match`. Expected: the filtered message. Clear the search. Expected: the rows return.
The console shows no error.

- [x] **Step 7: Commit**

Stage: the 6 SPA files above
Message: `fix(customers): say "nothing matches" when a drawer filter hides every row`

### Task 2: Fold the activity search into its repository

**Files:**
- Modify: `includes/Repositories/CustomerMetaRepository.php`: the class docblock, the last line of `paginate_activity()`, and two new private methods
- Modify: `includes/Repositories/LedgerRepository.php`: the `@return` of `history_where()`
- Delete: `includes/Repositories/CustomerMetaActivitySearch.php`

**Interfaces:**
- Produces:
  - `private function paginate_searched( array $where, string $search, int $page, int $per_page ): array` on `CustomerMetaRepository`, returning `array{items: CustomerMeta[], total: int, page: int, per_page: int}`;
  - `private static function activity_matches( CustomerMeta $item, string $search ): bool` on `CustomerMetaRepository`.

- [x] **Step 1: Move the search into the repository**

Move `CustomerMetaActivitySearch::paginate()` into `paginate_searched()`, which reads the table as `esc_sql( $this->table() )` itself. Move `matches()` into `activity_matches()`. Keep the `phpcs:disable`/`enable` pair, narrowing its reason to "the fragments in $where are already prepared by paginate_activity()".
`paginate_searched()`'s docblock opens with one line, "Activity rows matching $search in their real content, paginated in PHP." It then keeps the why from the deleted class's docblock: why the raw `meta_value` blob is never LIKEd, and why candidates are fetched with no LIMIT and paginated after filtering. It drops the "200-line cap" clause.

- [x] **Step 2: Call it, repoint the docblock, delete the class**

`paginate_activity()` ends with `return $this->paginate_searched( $where, $search, $page, $per_page );`. The class docblock's pointer becomes "see `paginate_searched()` for why and how." Then delete `includes/Repositories/CustomerMetaActivitySearch.php`.
In `LedgerRepository::history_where()`, make the `@return` line `@return string[] Already-prepared fragments, for AbstractRepository::paginate()'s \`where\`.`

- [x] **Step 3: Verify**

Run: `git grep -n CustomerMetaActivitySearch -- includes`. Expected: no output.
Run: `php -l` on both PHP files, then `composer lint` on them. Expected: `No syntax errors detected`, and no finding that the Task 0 baseline didn't have.
Run `drawer-check.php` with `WP eval-file`, save the output as `drawer-after-2.txt`, and diff it against `drawer-before.txt`. Expected: no output.

- [x] **Step 4: Commit**

Stage: `includes/Repositories/CustomerMetaRepository.php`, `includes/Repositories/LedgerRepository.php`, and the deletion of `includes/Repositories/CustomerMetaActivitySearch.php`
Message: `refactor(customers): keep the activity search SQL inside CustomerMetaRepository`

### Task 3: Drop the 200-line cap from every comment

**Files:**
- Modify, docblocks only:
  - `includes/Controllers/AbstractRestController.php` (`respond_paginated()`);
  - `includes/Controllers/ActivityRestController.php`, `RedemptionsRestController.php`, `ReferralsRestController.php`;
  - `includes/Controllers/CustomerItemFormatter.php`, `CustomerListFormatter.php`;
  - `includes/Controllers/Schemas/CustomerItemSchema.php`, `CustomerRouteArgs.php`, `LedgerQueryArgs.php`;
  - `apps/admin/src/pages/customers/drawer/customer-drawer.panels.copy.ts`, `apps/admin/src/pages/settings/design-settings.option-labels.ts`.

**Interfaces:** none

- [x] **Step 1: Rewrite each comment without the cap**

In each file, delete the clause that cites the cap and keep any other reason the comment gives:
- `respond_paginated()` is public because `CustomerListFormatter::respond_list()` calls it on the controller it is handed, without duplicating the envelope.
- The three separate controllers mirror the `LedgerRestController` shape.
- `CustomerItemFormatter` is pure array assembly, so it stays unit-testable without WordPress.
- `CustomerListFormatter` owns the list's route args and request-to-response handling, beside `CustomerRowFilters`/`CustomerRowSort`.
- `CustomerRouteArgs` and `LedgerQueryArgs` sit apart from their controllers, the way `ActivityQueryArgs` does.
- The panels copy is shared by the three header panels, which share one composition.
- The option labels are grouped as "maps a value" rather than "is a string".

Where no other reason is left, as in `CustomerItemSchema`, the docblock keeps only its opening line on what the class is. Change no code.

- [x] **Step 2: Verify**

Run: `git grep -n -i '200-line' -- includes apps`. Expected: no output.
Run: `composer lint` on the 9 PHP files, and eslint and prettier `--check` on the 2 SPA files. Expected: no finding that the Task 0 baseline didn't have.
Run: `git diff --stat`. Expected: only the 11 files above, all of them comment-only changes.

- [x] **Step 3: Commit**

Stage: the 11 files above
Message: `docs(comments): drop the retired 200-line cap from the split classes' docblocks`

### Task 4: Replace the ledger's two user indexes with one

**Files:**
- Modify: `includes/Database/Schema/LedgerTable.php`: `definition()`, `expected_indexes()`, the class docblock
- Modify: `includes/Database/Installer.php`: `run_migrations()`

**Interfaces:**
- Produces: index `idx_user_history_type` on the ledger table, columns `(user_id,id,type)`. It replaces `idx_user_history` and `idx_user_type` on that table.

- [x] **Step 1: Change the schema**

In `definition()`, replace the two lines `KEY idx_user_history (user_id,id),` and `KEY idx_user_type (user_id,type),` with `KEY idx_user_history_type (user_id,id,type),`. In `expected_indexes()`, replace `'idx_user_history'` and `'idx_user_type'` with `'idx_user_history_type'`. In the class docblock, replace the two index sentences with one: idx_user_history_type is (user_id, id, type). `id` orders history because created_at can tie within a second, and `type` lets the drawer's per-type count and type filter read the index instead of the rows.

- [x] **Step 2: Drop the replaced indexes**

In `Installer::run_migrations()`, after the existing `uniq_ledger_event` call, add `self::drop_index_if_exists( 'ledger', 'idx_user_history' );` and `self::drop_index_if_exists( 'ledger', 'idx_user_type' );`. Precede them with a one-line comment: idx_user_history_type replaced both, and dbDelta has already added it by the time this runs.

- [x] **Step 3: Lint**

Run: `php -l` and `composer lint` on both files. Expected: `No syntax errors detected`, and no finding that the Task 0 baseline didn't have.

- [x] **Step 4: Re-run the installer and verify**

Run `WP option delete yayloyalty_db_version`. The next `WP` command re-runs `install()`. Then run one `WP eval` that prints:
- `get_option( 'yayloyalty_db_version' )`. Expected: `1.0.0`.
- `SHOW INDEX` on `wp_yayloyalty_ledger`, as `Key_name Seq_in_index Column_name`. Expected: `idx_user_history_type` with `1 user_id`, `2 id` and `3 type`; no `idx_user_history`; no `idx_user_type`.
- `SHOW INDEX` on `wp_yayloyalty_customer_meta` where `Key_name = 'idx_user_history'`. Expected: 2 rows, `user_id` then `id`.
- `var_export( \YayLoyalty\Database\Installer::verify() )`. Expected: `array ( )`.
- The `key` and `Extra` of `EXPLAIN SELECT type, COUNT(*) AS row_count FROM wp_yayloyalty_ledger WHERE user_id = 2 GROUP BY type`. Expected: key `idx_user_history_type`, and Extra contains `Using index`.
- The `key` and `Extra` of `EXPLAIN SELECT * FROM wp_yayloyalty_ledger WHERE user_id = 2 ORDER BY id DESC LIMIT 10`. Expected: key `idx_user_history_type`, and Extra does not contain `Using filesort`.

The table holds only 10 rows. If the optimizer picks a full scan for either EXPLAIN, run `ANALYZE TABLE wp_yayloyalty_ledger` through `$wpdb` once and repeat. Record the outcome in the plan's "How it ran" section.

- [x] **Step 5: Compare with the baseline**

Run `drawer-check.php` with `WP eval-file`, save the output as `drawer-after-4.txt`, and diff it against `drawer-before.txt`. Expected: no output.

- [x] **Step 6: Commit**

Stage: `includes/Database/Schema/LedgerTable.php`, `includes/Database/Installer.php`
Message: `perf(ledger): fold the two user indexes into idx_user_history_type`

### Final verification

- [x] Repeat Task 1, step 6 in wp-admin. Expected: as there, with the drawer's segment counts unchanged by any filter.
- [x] Run `drawer-check.php` once more. Expected: the same output as `drawer-before.txt`.
- [x] Run `git grep -n -i '200-line' -- includes apps` and `git grep -n 'CustomerMetaActivitySearch\|idx_user_type' -- includes`. Expected: no output from the first; the second matches only the `drop_index_if_exists( 'ledger', 'idx_user_type' )` line in `Installer.php`.
- [x] Set this plan's Status to `done 2026-10-05`, add a short "How it ran" section, then stage `docs/plans/2026-10-05-drawer-filters-cleanup.md` with message `docs(plans): record the drawer filters cleanup`.
- [x] Run `git status`. Expected: clean, with the 5 commits above on top of `c98d35a`.

### How it ran

- `drawer-before.txt` was captured before any source edit: 14 ledger lines and 8 activity lines for users 2 and 3. `Manual` matched one activity row per user, so no other term was needed. The output was identical after Task 2, after Task 4, and at the end.
- Lint baselines were clean (phpcs, eslint and prettier on every touched file); `tsc --noEmit` showed only the 3 known `TS2307` errors before and after Task 1. Prettier reflowed the new `EMPTY_FILTERED_BODY` line, the only formatting change.
- Task 4's EXPLAINs used `idx_user_history_type` without `ANALYZE TABLE`: the count query showed `Using index; Using temporary`, and the history page showed `Backward index scan` with no filesort. `customer_meta`'s own `idx_user_history` was untouched.
- The wp-admin checks used Elena Popescu (user 5). Earned showed the filtered message, All brought back 2 rows, and the counts stayed All 2, Earned 0, Redeemed 0, Adjusted 2, Expired 0. In the activity log, `zzz-no-match` showed the filtered message, and clearing it brought back 3 rows.
- The only console error was a React `validateDOMNesting` warning about a Skeleton `<div>` inside a `<p>` in `CustomerDrawerHeader` while the drawer loads. That file is untouched by this plan, so the warning was already on the branch.
