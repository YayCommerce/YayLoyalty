# Ledger filters: fold the filter SQL into its repository and index the per-type counts

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is what this plan argues from.

**Status:** done 2026-10-05
**Goal:** finish the two follow-ups `/yaytidy` left on `paul/improve/ledger-queries`: the ledger tab's filter SQL moves back inside `LedgerRepository`, and the ledger table gets an index for the per-type counts and the type filter.
**Spec:** none, planned from the request
**Architecture:** `LedgerHistoryFilters` exists only to keep `LedgerRepository` under a 200-line cap that no rule sets any more, and it forced `like_fragment()` and `site_day_range_where()` on `AbstractRepository` to become `public static`. It becomes a private method of `LedgerRepository`, and both helpers go back to `protected` instance methods, so only repositories build SQL. The `type_counts` GROUP BY stays on every request, as the user chose: a `(user_id,type)` index makes it read only index entries instead of every one of the customer's rows. No API or SPA change.
**Touches:** `includes/Repositories/LedgerRepository.php`, `includes/Repositories/AbstractRepository.php` (shared: `CampaignRepository`, `RewardRepository`, `CustomerMetaRepository` call its helpers), `includes/Repositories/CustomerMetaRepository.php`, `includes/Database/Schema/LedgerTable.php`; deletes `includes/Repositories/LedgerHistoryFilters.php`

## Requirements

- **Goal:** the ledger tab filters and counts exactly as it does now, with its SQL owned by `LedgerRepository` and its per-type count served by an index.
- **Out of scope:**
  - moving the count to its own endpoint or fetching it once per customer (the user chose index only);
  - moving `LedgerQueryArgs::group_of()`/`types_of()`/`group_counts()` into `Enums\LedgerType`;
  - bumping `YAYLOYALTY_VERSION`: `./scripts/run.sh release` owns the version, and the plugin has no release yet;
  - adding automated tests (the "No tests" entry in `docs/known-issues.md` still holds).
- **Acceptance criteria:**
  1. `git grep -n LedgerHistoryFilters -- includes` prints nothing, and `includes/Repositories/LedgerHistoryFilters.php` does not exist.
  2. `AbstractRepository` declares `protected function like_fragment( string $column, string $term ): string` and `protected function site_day_range_where( string $after, string $before ): array`. Neither is `static`, and every caller uses `$this->`.
  3. For the same user and the same 4 argument sets, `LedgerRepository::paginate_for_user()` returns the same `total` and the same entry ids before and after Task 1.
  4. On a site where `install()` has re-run, `SHOW INDEX FROM wp_yayloyalty_ledger WHERE Key_name = 'idx_user_type'` returns 2 rows, `user_id` (Seq_in_index 1) and `type` (Seq_in_index 2), and `Installer::verify()` returns `[]`.
  5. `EXPLAIN` of the `count_by_type_for_user()` query uses key `idx_user_type` with `Using index` in Extra.
  6. End to end: in wp-admin, the customer drawer's ledger tab, with the Earned segment, a `#<order id>` search and a date range applied in turn, narrows the rows, and every segment keeps its unfiltered count.
- **Decided with the user (2026-10-05):**
  - How should the ledger tab stop recounting `type_counts` on every request? Index only: add `idx_user_type (user_id,type)` and keep the per-request count.
  - Where does this land? On `paul/improve/ledger-queries`, after the user commits the `/yaytidy` edits.

## Global constraints

- Product PHP must run on PHP 7.4 (`CLAUDE.md` hard rule 4, `.claude/rules/php-standards.md` § PHP 7.4).
- SQL: values go through `$wpdb->prepare()` placeholders, table and column names go in backticks through `esc_sql()`, and IN lists come from `Sql::in_placeholders()`.
- A table's `definition()` uses `KEY name (cols)` with no space after the comma inside the column list (docblock of `AbstractTableSchema`).
- Comments: a docblock opens with one line on what the code does for the caller, then only the why. No comment cites a doc path, section number, feature ID or artboard ID.
- Lint only what you changed with `composer lint -- <files>`; a run over the whole repo fails today (`docs/known-issues.md`).
- Run `wp` commands from Local's "Open site shell" for this site. A plain terminal fails with `Error establishing a database connection`, because Local runs MySQL on its own socket.
- Commit messages follow Conventional Commits, `type(scope): summary`, and end with the `Co-Authored-By` trailer that `CLAUDE.md` asks for. Stage files by name. Don't push: the user publishes from GitHub Desktop.

## Review focus

- **Existing installs don't get the index on their own.** `yayloyalty_db_version` holds the plugin version, so `Installer::maybe_upgrade()` re-runs `install()`, and with it dbDelta, only when `YAYLOYALTY_VERSION` changes. A dev site keeps working without the index, only slower. Task 2, step 3 forces the re-run on the Local site. Anyone else on the branch must run `wp option delete yayloyalty_db_version` once.
- **The search fragment's precedence.** The `order_id = %d OR note LIKE …` pair must stay wrapped in parentheses when it moves, or it binds to the `user_id = %d` fragment and leaks other customers' rows. Task 1, step 6 compares the `#<order id>` search's results before and after.
- **`like_fragment()` going back to an instance method.** `CampaignRepository` and `RewardRepository` already call `$this->like_fragment()`; nothing outside `includes/Repositories/` may call it. Task 1, step 4 checks this.

---

### Task 0: Start from the committed tidy edits

**Files:** none

- [x] **Step 1: Check the tree is clean**

Run: `git status --porcelain`
Expected: only `?? docs/plans/2026-10-05-ledger-filters-follow-ups.md`. If the 5 `/yaytidy` files are still listed (`DrawerTabFilters.tsx`, `CampaignPerformanceRestController.php`, `AbstractRepository.php`, `CustomerMetaRepository.php`, `LedgerHistoryFilters.php`), stop and ask the user to commit them. Never commit them for the user.

- [x] **Step 2: Capture the baselines**

From Local's site shell, find the customer with the most ledger rows:
`wp db query "SELECT user_id, COUNT(*) AS c FROM wp_yayloyalty_ledger GROUP BY user_id ORDER BY c DESC LIMIT 1"`
Pick an order id from that user's rows with `wp db query "SELECT order_id FROM wp_yayloyalty_ledger WHERE user_id = <user> AND order_id IS NOT NULL LIMIT 1"`. The date range below is 2026-09-01 to 2026-09-30; if that user has no rows in it, use a month that holds some, here and in every later step. Then save the output of this command to the scratchpad as `ledger-before.txt`:

```
wp eval '$r = new \YayLoyalty\Repositories\LedgerRepository();
foreach ( [ [], [ "types" => [ "earn", "refund_earn" ] ], [ "search" => "#<order id>" ], [ "after" => "2026-09-01", "before" => "2026-09-30" ] ] as $a ) {
    $p = $r->paginate_for_user( <user>, $a + [ "per_page" => 100 ] );
    echo $p["total"], " ", implode( ",", array_map( fn( $e ) => $e->get_id(), $p["items"] ) ), "\n";
}'
```

Expected: 4 lines. The first line's total equals the `c` from the first query.
Run `composer lint -- includes/Repositories/LedgerRepository.php includes/Repositories/AbstractRepository.php includes/Repositories/CustomerMetaRepository.php includes/Database/Schema/LedgerTable.php`, and keep its findings as the lint baseline.

### Task 1: Fold the ledger filters into `LedgerRepository`

**Files:**
- Modify: `includes/Repositories/LedgerRepository.php`: `paginate_for_user()` and its docblock, plus a new private method
- Modify: `includes/Repositories/AbstractRepository.php`: `like_fragment()` and `site_day_range_where()`
- Modify: `includes/Repositories/CustomerMetaRepository.php`: the `site_day_range_where()` call in `paginate_activity()`
- Delete: `includes/Repositories/LedgerHistoryFilters.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `private function history_where( array $args ): array` on `LedgerRepository`. It takes `types?: string[]`, `search?: string`, `after?: string`, `before?: string` and returns prepared WHERE fragments.
  - `protected function like_fragment( string $column, string $term ): string` and `protected function site_day_range_where( string $after, string $before ): array` on `AbstractRepository`.

- [x] **Step 1: Make both `AbstractRepository` helpers protected instance methods**

Change `public static function like_fragment` to `protected function like_fragment`, and `public static function site_day_range_where` to `protected function site_day_range_where`. Bodies and docblocks stay as they are.

- [x] **Step 2: Add `LedgerRepository::history_where()`**

Move the body of `LedgerHistoryFilters::where()` into `private function history_where( array $args ): array`, calling `$this->site_day_range_where()` and `$this->like_fragment()`. The docblock opens with one line on what it returns. It keeps the why from the deleted class's docblock: row descriptions are composed client-side, so search reaches only what is stored, a bare or `#`-prefixed number is also an exact `order_id`, and any text matches `note`. It also keeps the `after`/`before` line (site-calendar `Y-m-d` days, both inclusive). Drop the "200-line cap" sentence. Keep the `phpcs:ignore` on the `type IN (…)` line, and keep the parentheses around the `order_id … OR note …` pair.

- [x] **Step 3: Point `paginate_for_user()` and the activity repository at the instance helpers**

In `paginate_for_user()`, replace `LedgerHistoryFilters::where( $args )` with `$this->history_where( $args )`, and make the `@param` line name `history_where()` in place of `LedgerHistoryFilters::where()`.
In `CustomerMetaRepository::paginate_activity()`, replace `self::site_day_range_where(` with `$this->site_day_range_where(`.
Then delete `includes/Repositories/LedgerHistoryFilters.php`.

- [x] **Step 4: Check that no static or outside call is left**

Run: `git grep -n 'LedgerHistoryFilters\|::like_fragment\|::site_day_range_where' -- includes`. Expected: no output.
Run: `git grep -n 'like_fragment(\|site_day_range_where(' -- includes`. Expected:
- the two `protected function` definitions in `AbstractRepository.php`;
- `$this->` calls in `CampaignRepository.php`, `RewardRepository.php`, `CustomerMetaRepository.php`, and twice in `LedgerRepository.php`.

- [x] **Step 5: Lint**

Run: `php -l` on each of the 3 modified files. Expected: `No syntax errors detected` for each.
Run: `composer lint -- includes/Repositories/LedgerRepository.php includes/Repositories/AbstractRepository.php includes/Repositories/CustomerMetaRepository.php`. Expected: no finding that the Task 0 baseline didn't have.

- [x] **Step 6: Compare with the baseline**

From the site shell, re-run the Task 0, step 2 `wp eval` with the same user and order id, and save it as `ledger-after.txt`.
Run: `diff ledger-before.txt ledger-after.txt`. Expected: no output.
Run: `wp eval 'print_r( ( new \YayLoyalty\Repositories\CustomerMetaRepository() )->paginate_activity( <user>, [ "event_types" => \YayLoyalty\Enums\ActivityEvent::ALL, "after" => "2026-09-01", "before" => "2026-09-30" ] )["total"] );'`. Expected: an integer and no PHP error.

- [x] **Step 7: Commit**

Stage: `includes/Repositories/LedgerRepository.php`, `includes/Repositories/AbstractRepository.php`, `includes/Repositories/CustomerMetaRepository.php`, and the deletion of `includes/Repositories/LedgerHistoryFilters.php`
Message: `refactor(ledger): keep the history filter SQL inside LedgerRepository`

### Task 2: Index the ledger by user and type

**Files:**
- Modify: `includes/Database/Schema/LedgerTable.php`: `definition()`, `expected_indexes()` and the class docblock

**Interfaces:**
- Consumes: nothing.
- Produces: index `idx_user_type` on the ledger table, columns `(user_id,type)`.

- [x] **Step 1: Add the index**

In `definition()`, add `KEY idx_user_type (user_id,type),` directly after `KEY idx_user_history (user_id,id),`. In `expected_indexes()`, add `'idx_user_type'` after `'idx_user_history'`. In the class docblock, add one sentence after the `idx_user_history` sentence: idx_user_type lets the drawer's per-type counts and type filter read index entries instead of every one of the customer's rows.

- [x] **Step 2: Lint**

Run: `php -l includes/Database/Schema/LedgerTable.php`. Expected: `No syntax errors detected`.
Run: `composer lint -- includes/Database/Schema/LedgerTable.php`. Expected: no finding that the Task 0 baseline didn't have.

- [x] **Step 3: Re-run the installer on the Local site**

From the site shell, run `wp option delete yayloyalty_db_version`. The next request, the next `wp` command included, re-runs `install()` through `Installer::maybe_upgrade()` on `plugins_loaded`.
Run: `wp option get yayloyalty_db_version`. Expected: `1.0.0`.

- [x] **Step 4: Verify the index**

Run: `wp db query "SHOW INDEX FROM wp_yayloyalty_ledger WHERE Key_name = 'idx_user_type'"`. Expected: 2 rows, `user_id` with Seq_in_index 1 and `type` with Seq_in_index 2.
Run: `wp eval 'var_export( \YayLoyalty\Database\Installer::verify() );'`. Expected: `array ( )`.
Run: `wp db query "EXPLAIN SELECT type, COUNT(*) AS row_count FROM wp_yayloyalty_ledger WHERE user_id = <user> GROUP BY type"`. Expected: `key` is `idx_user_type` and `Extra` contains `Using index`.

- [x] **Step 5: Commit**

Stage: `includes/Database/Schema/LedgerTable.php`
Message: `perf(ledger): index ledger rows by user and type for the drawer's counts`

### Final verification

- [x] In wp-admin, open Customers, then open the drawer for the Task 0 user and its Ledger tab. Note each segment's count. Then apply, one at a time, the Earned segment, a search for `#<order id>`, and a date range of 2026-09-01 to 2026-09-30. Expected: each one narrows the rows, every segment keeps the count noted at the start, and the browser console shows no error.
- [x] Re-run Task 0, step 2's `wp eval` once more. Expected: the same output as `ledger-before.txt`.
- [x] Set this plan's Status to `done 2026-10-05`, then stage `docs/plans/2026-10-05-ledger-filters-follow-ups.md` with message `docs(plans): record the ledger filter follow-ups`.
- [x] Run `git status`. Expected: clean, with the 3 commits above on top of the user's tidy commit.

### How it ran

- `wp` ran from a plain terminal as `php -d mysqli.default_socket=<the site's Local socket> $(which wp) eval …`, not from Local's site shell; `wp db query` still fails there, so the SQL checks went through `$wpdb` in `wp eval`.
- The Local site has 10 ledger rows, all `adjust`, all on 2026-10-02, and none with an `order_id`. The baseline therefore covered 7 argument sets for users 2 and 3 (both type groups, a `note` search, a `#2` search, an in-range and an empty date range) plus one activity total, and it matched before and after both tasks. No data exercises the `order_id … OR note …` branch, so its parentheses were checked by reading the code.
- The drawer check used Elena Popescu (user 5): Earned showed no rows, a "Manual" search showed 1 of 2, and 2026-10-03 to 2026-10-10 showed none. Every segment kept All 2, Earned 0, Redeemed 0, Adjusted 2, Expired 0, and the console showed no error while the filters ran.
