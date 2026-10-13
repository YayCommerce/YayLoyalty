# Points Lots Schema Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** Install the Pro tables `yayloyalty_lots` and `yayloyalty_lot_allocations`, and make the Installer create tables that Pro registers through `yayloyalty_table_schemas` on activation, on a Lite→Pro swap and on installs that ran without WooCommerce.
**Spec:** [docs/specs/2026-10-09-points-lots-schema-design.md](../specs/2026-10-09-points-lots-schema-design.md)
**Architecture:** Pro adds two `AbstractTableSchema` subclasses through the existing `yayloyalty_table_schemas` filter, so Lite sites never get the tables. Because that filter is not attached during activation or without WooCommerce, `Installer` also stores the slugs it installed in `yayloyalty_db_tables` and re-runs `install()` when a registered slug is missing, not only when the version changes.
**Touches:** `includes/Pro/Database/Schema/` (new), `includes/Pro/YayLoyaltyPro.php`, `includes/Database/Installer.php` (shared: both builds, every request via `maybe_upgrade()`; consumers are `yayloyalty.php` (plugins_loaded 20), `ActDeact::activate()` / `on_new_site()`, and `tests/integration/bootstrap.php`, which reads `Installer::schemas()`), `docs/architecture.md`, `docs/decisions/`.
**Tests:** `tests/integration/Pro/InstallerProTablesTest.php`, integration suite: guards that `install()` + `verify()` produce both Pro tables cleanly and that `maybe_upgrade()` re-installs when a registered slug is missing from `yayloyalty_db_tables` at an unchanged version. Accepted in the spec, 2026-10-09.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite names Pro in exactly one place: the guarded `YayLoyaltyPro::boot()` call in `includes/YayLoyalty.php`. Pro attaches only through the filters that `boot()` adds.
- dbDelta rules from `AbstractTableSchema`: `KEY name (...)` / `UNIQUE KEY name (...)`, two spaces after `PRIMARY KEY`, one space between column and type, `ENGINE=InnoDB`.
- All datetimes UTC (`Utils\Dates`). Points are integers; no money columns.
- Only `LedgerService` writes ledger rows and balances; this plan adds no writer to any table.
- Never hand-edit `vendor/`, `vendor-prefixed/`, `assets/dist/`, `release/`.
- Branch from `dev/edd` as `<name>/feat/points-lots-schema`; commits follow Conventional Commits and end with the `Co-Authored-By` trailer from CLAUDE.md.

## Review focus

- **Fresh Pro activation:** `ActDeact::activate()` runs `install()` before `plugin_init` boots Pro, so the stored slug list lacks `lots`; the next request must create both tables. Verified in Task 4 Step 2.
- **Pro site with WooCommerce inactive:** Pro does not boot, `schemas()` lacks the Pro slugs; `maybe_upgrade()` must not run `install()` on every request. Verified in Task 2 Step 6.
- **Integration test touches real options:** the bootstrap moves tables, not `$wpdb->options`, so the test must restore `yayloyalty_db_version` and `yayloyalty_db_tables` exactly (including "absent"). Owned by Task 2 Step 1.
- **Existing installs at the same version:** `yayloyalty_db_tables` is absent, so the first request after deploy runs `install()` once and then stops. Verified in Task 2 Step 6.

---

### Task 1: Pro schema classes and their registration

**Files:**
- Create: `includes/Pro/Database/Schema/LotsTable.php`
- Create: `includes/Pro/Database/Schema/LotAllocationsTable.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (imports, `boot()`, one new public static method)

**Interfaces:**
- Consumes: `YayLoyalty\Database\Schema\AbstractTableSchema` (`slug()`, `definition()`, `expected_columns()`, `expected_indexes()`, `table_name()`, `charset_collate()`).
- Produces: `YayLoyalty\Pro\Database\Schema\LotsTable` (slug `lots`), `YayLoyalty\Pro\Database\Schema\LotAllocationsTable` (slug `lot_allocations`), `YayLoyaltyPro::add_table_schemas( array $schemas ): array`.

- [ ] **Step 1: Implement `final class LotsTable extends AbstractTableSchema`**

`slug()` returns `'lots'`. `definition()` returns, in `LedgerTable.php`'s style:

```sql
CREATE TABLE {$table} (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint unsigned NOT NULL,
    ledger_id bigint unsigned DEFAULT NULL,
    points bigint unsigned NOT NULL,
    remaining bigint unsigned NOT NULL,
    expires_at datetime DEFAULT NULL,
    status varchar(10) NOT NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_ledger_id (ledger_id),
    KEY idx_user_open (user_id,status,expires_at),
    KEY idx_status_expires (status,expires_at)
) {$this->charset_collate()} ENGINE=InnoDB
```

`expected_columns()` lists the nine columns in order; `expected_indexes()` returns `PRIMARY`, `uniq_ledger_id`, `idx_user_open`, `idx_status_expires`. The class docblock states, briefly: one lot per positive ledger row while Pro lot expiry is on; `ledger_id` NULL = opening lot; `remaining = points − SUM(allocations.points)`; `expires_at` NULL = never expires; FIFO order `expires_at IS NULL, expires_at, id`; no writer yet (spec link).

- [ ] **Step 2: Implement `final class LotAllocationsTable extends AbstractTableSchema`**

`slug()` returns `'lot_allocations'`. DDL:

```sql
CREATE TABLE {$table} (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    lot_id bigint unsigned NOT NULL,
    ledger_id bigint unsigned NOT NULL,
    points bigint NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_lot_ledger (lot_id,ledger_id),
    KEY ledger_id (ledger_id)
) {$this->charset_collate()} ENGINE=InnoDB
```

`expected_indexes()` returns `PRIMARY`, `uniq_lot_ledger`, `ledger_id`. Docblock: append-only, never updated or deleted; `points` signed and never 0 (positive = taken from the lot, negative = returned by a `refund_redeem`); `uniq_lot_ledger` is the double-allocation backstop.

- [ ] **Step 3: Register them in `YayLoyaltyPro`**

In `boot()`, add `add_filter( 'yayloyalty_table_schemas', [ self::class, 'add_table_schemas' ] );`. Add `public static function add_table_schemas( array $schemas ): array` that appends `new LotsTable()` and `new LotAllocationsTable()`. Docblock with `@param AbstractTableSchema[] $schemas` / `@return AbstractTableSchema[]`.

- [ ] **Step 4: Verify the schemas are registered and lint clean**

Run from the site root `/Users/nguyentruong/Valet/brandy`:
`php -r 'require "wp-load.php"; foreach ( \YayLoyalty\Database\Installer::schemas() as $s ) { echo $s->slug(), PHP_EOL; }'`
Expected: `customers`, `ledger`, `campaigns`, `rewards`, `customer_meta`, `lots`, `lot_allocations`.

Run: `composer lint -- includes/Pro/Database/Schema includes/Pro/YayLoyaltyPro.php`
Expected: no errors in the new files; `YayLoyaltyPro.php` reports nothing beyond what `dev/edd` reports for it.

- [ ] **Step 5: Commit**

Stage: `includes/Pro/Database/Schema/LotsTable.php`, `includes/Pro/Database/Schema/LotAllocationsTable.php`, `includes/Pro/YayLoyaltyPro.php`
Message: `feat(pro): add lots and lot allocations table schemas`

### Task 2: Installer tracks installed table slugs

**Files:**
- Modify: `includes/Database/Installer.php` (constant, `install()`, `maybe_upgrade()`, new private helpers)
- Test: `tests/integration/Pro/InstallerProTablesTest.php`

**Interfaces:**
- Consumes: Task 1's two schema classes via `Installer::schemas()`.
- Produces: `Installer::OPTION_DB_TABLES = 'yayloyalty_db_tables'` (autoloaded `string[]` of slugs); `private static function missing_slugs(): array` (registered slugs absent from the option); `private static function needs_install(): bool` (version differs OR `missing_slugs()` is non-empty).

- [ ] **Step 1: Write the failing test**

`final class InstallerProTablesTest extends IntegrationTestCase`, namespace `YayLoyalty\Tests\Integration\Pro`.
- `setUp()`: `markTestSkipped` unless `\YayLoyalty\YayLoyalty::is_pro()`; snapshot the raw values of `yayloyalty_db_version` and `yayloyalty_db_tables` (`get_option( $key, null )`).
- `tearDown()`: restore each snapshot (`delete_option` when it was `null`, else `update_option`); re-create any dropped scratch table from `Installer::schemas()` so later tests still have it.
- A helper drops a scratch table only when its name starts with `YAYLOYALTY_TEST_PREFIX` (the same guard as `RewardRouteTest::drop_redemptions_table()`).

```php
public function test_install_creates_both_pro_tables_with_their_indexes(): void {
    $this->drop_scratch_table( 'lots' );
    $this->drop_scratch_table( 'lot_allocations' );

    Installer::install();

    $problems = Installer::verify();
    $this->assertArrayNotHasKey( Table::name( 'lots' ), $problems );
    $this->assertArrayNotHasKey( Table::name( 'lot_allocations' ), $problems );
    $this->assertSame( [], array_diff( [ 'lots', 'lot_allocations' ], (array) get_option( Installer::OPTION_DB_TABLES ) ) );
}

public function test_maybe_upgrade_installs_a_registered_table_missing_at_the_same_version(): void {
    update_option( Installer::OPTION_DB_VERSION, YAYLOYALTY_VERSION );
    update_option( Installer::OPTION_DB_TABLES, [ 'customers', 'ledger', 'campaigns', 'rewards', 'customer_meta' ] );
    $this->drop_scratch_table( 'lots' );

    Installer::maybe_upgrade();

    $this->assertTrue( $this->table_exists( 'lots' ) );
    $this->assertContains( 'lots', (array) get_option( Installer::OPTION_DB_TABLES ) );
}

public function test_maybe_upgrade_does_nothing_when_version_and_slugs_match(): void {
    Installer::install();
    $this->drop_scratch_table( 'lots' ); // Recorded as installed, so maybe_upgrade must not notice.

    Installer::maybe_upgrade();

    $this->assertFalse( $this->table_exists( 'lots' ) );
}
```

`table_exists( string $slug ): bool` runs `SHOW TABLES LIKE` on `Table::name( $slug )` with `$wpdb->prepare`.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `YAYLOYALTY_TEST_SOCKET=<socket> composer test:integration -- --filter InstallerProTablesTest`
Expected: FAIL, `Undefined class constant 'OPTION_DB_TABLES'`. Read the output: exit 0 without test lines means the bootstrap never ran (docs/known-issues.md).

- [ ] **Step 3: Implement the slug tracking in `includes/Database/Installer.php`**

- `public const OPTION_DB_TABLES = 'yayloyalty_db_tables';` with a one-line comment: tables Pro registers through the filter are absent during activation, so the version alone cannot tell whether they were installed.
- `install()`: after `self::verify()` and before the version stamp, `update_option( self::OPTION_DB_TABLES, <slugs of self::schemas()>, true )`. Store every slug, including tables `verify()` flagged, matching how the version is stamped today (no retry loop on a table dbDelta can't fix).
- `maybe_upgrade()`: replace both version comparisons (the early return and the in-lock re-check) with `self::needs_install()`.
- `missing_slugs()`: `array_diff( registered slugs, (array) get_option( self::OPTION_DB_TABLES, [] ) )`. Only "registered but not stored" counts, so a Pro site without WooCommerce (fewer registered slugs) never re-installs.
- Update the class docblock's first sentence to mention the slug check.

- [ ] **Step 4: Run the test to confirm it passes**

Run: `YAYLOYALTY_TEST_SOCKET=<socket> composer test:integration -- --filter InstallerProTablesTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Run the whole integration suite, unit suite and lint**

Run: `composer lint -- includes/Database/Installer.php tests/integration/Pro/InstallerProTablesTest.php`
Expected: no errors in the lines this task changed.


Run: `YAYLOYALTY_TEST_SOCKET=<socket> composer test:integration` then `composer test`
Expected: all pass, same counts as `dev/edd` plus 3.

- [ ] **Step 6: Verify the two edge cases on the local site**

From `/Users/nguyentruong/Valet/brandy`:
1. Existing install, option absent: `php -r 'require "wp-load.php"; delete_option( "yayloyalty_db_tables" );'`, then load `/wp-admin/` once, then `php -r 'require "wp-load.php"; var_dump( get_option( "yayloyalty_db_tables" ) );'`
   Expected: an array of 7 slugs ending `lots`, `lot_allocations`. (That it then stops re-installing is the third test's job.)
2. WooCommerce inactive: deactivate WooCommerce in `/wp-admin/plugins.php`, load `/wp-admin/` twice, then check `get_option( "yayloyalty_db_tables" )` still holds 7 slugs. A re-install there would have rewritten it to the 5 Lite slugs. Reactivate WooCommerce.

- [ ] **Step 7: Commit**

Stage: `includes/Database/Installer.php`, `tests/integration/Pro/InstallerProTablesTest.php`
Message: `feat(installer): reinstall when a registered table was never installed`

### Task 3: Architecture doc and decision record

**Files:**
- Create: `docs/decisions/2026-10-09-pro-tables-via-schema-filter.md`
- Modify: `docs/architecture.md` § Data and upgrades (lines 81-93) and § Pro and Lite (`boot()` bullet, line 103)

**Interfaces:**
- Consumes: Task 1 and Task 2 names.
- Produces: none.

- [ ] **Step 1: Write the decision record**

Follow `docs/decisions/2026-10-04-decision-record-practice.md` (format, sections, statuses). Decisions: Pro registers its own tables through `yayloyalty_table_schemas`, so Lite sites never have them; `Installer` re-runs `install()` when a registered slug is missing from `yayloyalty_db_tables`, as well as on a version change; a downgrade leaves Pro tables in place, unverified and unwritten by Lite. Context: activation runs before Pro boots; same-version Lite→Pro swap; WooCommerce inactive. Rejected: core-registered tables in both builds, Pro-owned dbDelta with `yayloyalty_pro_db_version`, fingerprinting `yayloyalty_db_version`. Status: `Proposed`, until the user accepts it in review.

- [ ] **Step 2: Update `docs/architecture.md`**

- § Data and upgrades: the filter is now hooked by Pro (`LotsTable`, `LotAllocationsTable` in `includes/Pro/Database/Schema/`); `install()` stores installed slugs in `yayloyalty_db_tables` before the version stamp; `maybe_upgrade` re-runs on a version change or a registered slug missing from that option. Replace "A schema change therefore reaches existing sites only through a version bump" with: a changed table reaches existing sites through a version bump; a newly registered table also arrives on the next request. Link the decision record.
- § Pro and Lite: add the table-schema filter to the `boot()` bullet's list of what Pro changes.

- [ ] **Step 3: Verify links**

Run: `git grep -n "pro-tables-via-schema-filter" docs`
Expected: the record file exists and `docs/architecture.md` links it with a path that resolves.

- [ ] **Step 4: Commit**

Stage: `docs/decisions/2026-10-09-pro-tables-via-schema-filter.md`, `docs/architecture.md`
Message: `docs(architecture): record pro tables via the schema filter`

### Task 4: End-to-end verification

**Files:** none modified.

- [ ] **Step 1: Confirm Lite stays clean**

`./scripts/run.sh release` fails today (docs/known-issues.md), so the zip validator can't run. Instead run:
`git grep -n -E "LotsTable|LotAllocationsTable|Pro\\\\Database" -- includes ':!includes/Pro'`
Expected: no output (only `includes/Pro/` names the new classes).

- [ ] **Step 2: Fresh activation on the local Pro site (spec acceptance 9)**

1. In `/wp-admin/plugins.php`, deactivate YayLoyalty. While it is inactive nothing re-creates its tables, so drop both and clear the option:
   `php -r 'require "wp-load.php"; global $wpdb; foreach ( [ "lots", "lot_allocations" ] as $s ) { $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}yayloyalty_$s" ); } delete_option( "yayloyalty_db_tables" );'`
2. Activate YayLoyalty. Activation's `install()` runs without Pro's filter; the redirect back to `plugins.php` is the next request, whose `maybe_upgrade()` must add the Pro tables.
3. `php -r 'require "wp-load.php"; global $wpdb; print_r( $wpdb->get_col( "SHOW TABLES LIKE \"%yayloyalty_lot%\"" ) ); var_dump( \YayLoyalty\Database\Installer::verify() );'`
   Expected: both `{prefix}yayloyalty_lots` and `{prefix}yayloyalty_lot_allocations` listed, then `array(0) {}`.

- [ ] **Step 3: Unique-key behaviour (spec acceptance 6 and 7)**

Run, inside a rolled-back transaction so the site keeps no rows:
`php -r 'require "wp-load.php"; global $wpdb; $l = \YayLoyalty\Utils\Table::name( "lots" ); $a = \YayLoyalty\Utils\Table::name( "lot_allocations" ); $wpdb->query( "START TRANSACTION" ); $row = "(1, %s, 10, 10, NULL, \"open\", UTC_TIMESTAMP(), UTC_TIMESTAMP())"; $ins = "INSERT INTO $l (user_id, ledger_id, points, remaining, expires_at, status, created_at, updated_at) VALUES "; var_dump( $wpdb->query( $ins . sprintf( $row, "NULL" ) ), $wpdb->query( $ins . sprintf( $row, "NULL" ) ), $wpdb->query( $ins . sprintf( $row, "999" ) ), $wpdb->query( $ins . sprintf( $row, "999" ) ) ); $q = "INSERT INTO $a (lot_id, ledger_id, points, created_at) VALUES (1, 5, 3, UTC_TIMESTAMP())"; var_dump( $wpdb->query( $q ), $wpdb->query( $q ) ); $wpdb->query( "ROLLBACK" );'`
Expected: `int(1) int(1) int(1) bool(false)` then `int(1) bool(false)` (the `false`s are duplicate-key errors).

- [ ] **Step 4: Mark the plan done**

Set this file's Status to `done YYYY-MM-DD` in the PR that lands the last task.
Stage: `docs/plans/2026-10-09-points-lots-schema.md`
Message: `docs(plans): mark points lots schema done`
