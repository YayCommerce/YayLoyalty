# Phase 1: Free inactivity expiry

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing. Branch `paul/feat/expires-point` (carries `64c230d`).
**Delivers:** in both builds a merchant turns expiry on under Settings → Expiry, and an inactive customer's balance expires
through a daily job, as an `expire` ledger row, with the "Points expired" email (off by default in WooCommerce → Emails).
**Exit criterion:** `composer test:integration -- --filter 'LotWriterTest|InstallerLotTablesTest'` passes, and spec criterion 14 holds
under the inactivity model (Task 5, Step 6).

### Task 1: Install the lot tables in both builds

**Files:**
- Move: `includes/Pro/Database/Schema/LotsTable.php` → `includes/Database/Schema/LotsTable.php` (namespace `YayLoyalty\Database\Schema`)
- Move: `includes/Pro/Database/Schema/LotAllocationsTable.php` → `includes/Database/Schema/LotAllocationsTable.php`
- Modify: `includes/Database/Installer.php` (`schemas()`, `install()`, `maybe_upgrade()`, class docblock)
- Modify: `includes/Pro/YayLoyaltyPro.php` (remove `add_table_schemas()`, its `use` lines and hook)
- Modify: `docs/decisions/2026-10-09-pro-tables-via-schema-filter.md` (Status → superseded. The decision-record practice keeps replaced records as history, so it isn't deleted.)
- Create: `docs/decisions/2026-10-13-lots-in-core.md`
- Modify: `docs/architecture.md:92,99,100,112`
- Delete: `tests/integration/Pro/InstallerProTablesTest.php`
- Test: `tests/integration/InstallerLotTablesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: README contract "Tables".

- [x] **Step 1: Write the failing test** `tests/integration/InstallerLotTablesTest.php`

Base it on the deleted Pro test's `setUp`/`tearDown` without the Pro skip. The test calls `remove_all_filters( 'yayloyalty_table_schemas' )` and restores the hook in `tearDown`, so it proves the tables are core in either build. Restore only `Installer::OPTION_DB_VERSION`, and keep the
tearDown that recreates any missing registered table.

```php
public function test_install_creates_both_lot_tables_with_their_indexes(): void {
    $this->drop_scratch_table( 'lots' );
    $this->drop_scratch_table( 'lot_allocations' );

    Installer::install();

    $this->assertTrue( $this->table_exists( 'lots' ) );
    $this->assertTrue( $this->table_exists( 'lot_allocations' ) );
    $this->assertSame( [], Installer::verify() );
}
```

- [x] **Step 2: Run it**

Run: `composer test:integration -- --filter InstallerLotTablesTest`
Expected: FAIL. On a Lite build or before the move, `lots` is not in `Installer::schemas()`, so `table_exists( 'lots' )` is false after `install()`.

- [x] **Step 3: Move the schema classes and list them in `Installer::schemas()`** after `CustomerMetaTable`

Update the `LotsTable` docblock. Every positive ledger row opens a lot in both builds. `ledger_id` NULL is allowed by the
schema, but nothing writes it. Drop "while Pro lot expiry is on" and the "Nothing writes this table yet" line.

- [x] **Step 4: Revert the slug tracking in `Installer.php`**

Remove `OPTION_DB_TABLES`, `missing_slugs()` and `registered_slugs()`, the `update_option( self::OPTION_DB_TABLES, … )` line, and
the slug half of `needs_install()` (it becomes the version check only). Restore the class docblock's first sentence to
"Creates and upgrades the plugin's tables on a version change." Keep the `yayloyalty_table_schemas` filter, which predates `64c230d`.

- [x] **Step 5: Remove Pro's table hook**

In `YayLoyaltyPro.php`, delete `add_table_schemas()`, the two `use YayLoyalty\Pro\Database\Schema\…` lines and the `yayloyalty_table_schemas` `add_filter`.

- [x] **Step 6: Docs**

Set the 2026-10-09 decision record's Status to superseded by the new one. Write `docs/decisions/2026-10-13-lots-in-core.md`, following
`docs/decisions/2026-10-04-decision-record-practice.md`, with Status `Accepted, 2026-10-13`:
- Both builds keep `lots` and `lot_allocations`, because Free expires points too.
- `LedgerService` writes lots through `LotWriter` in the ledger row's own transaction, under the customer lock.
- The Pro-only filter install path and `yayloyalty_db_tables` were rejected.

In `docs/architecture.md`:
- Line 92: drop the Pro sentence and list lots as core tables.
- Line 99: remove it.
- Line 100: drop "or when a registered slug is missing…" and the last sentence.
- Line 112: drop "Pro's own tables arrive the same way…".

- [x] **Step 7: Verify**

Run: `composer test:integration -- --filter InstallerLotTablesTest`
Expected: PASS.
Run: `git grep -n "OPTION_DB_TABLES\|yayloyalty_db_tables\|Pro\\\\Database\\\\Schema\|pro-tables-via-schema-filter" -- includes tests docs/architecture.md docs/decisions`
Expected: no output.
Run: `composer lint -- includes/Database/Installer.php includes/Database/Schema/LotsTable.php includes/Database/Schema/LotAllocationsTable.php includes/Pro/YayLoyaltyPro.php`
Expected: no errors.

- [ ] **Step 8: Commit**

Stage: the moved and deleted files above, `includes/Database/Installer.php`, `includes/Pro/YayLoyaltyPro.php`, `docs/architecture.md`,
`docs/decisions/2026-10-13-lots-in-core.md`, `tests/integration/InstallerLotTablesTest.php`, `docs/specs/2026-10-09-points-lots-schema-design.md`,
`docs/specs/2026-10-13-points-expiry-design.md`, `docs/plans/2026-10-13-points-expiry/`
Message: `refactor(db): install the lot tables in both builds`

### Task 2: Write lots and allocations with every ledger row

**Files:**
- Create: `includes/Repositories/LotRepository.php`
- Create: `includes/Repositories/LotAllocationRepository.php` (split out: a repository touches one table)
- Modify: `docs/architecture.md` (writers section)
- Create: `includes/Services/LotWriter.php`
- Modify: `includes/Services/LedgerService.php` (`__construct`, `append()`, class docblock)
- Test: `tests/integration/Services/LotWriterTest.php`

**Interfaces:**
- Consumes: Task 1 tables.
- Produces: README contract `LotRepository` (the first four methods), `LotWriter::__construct`, and `LotWriter::record`.

- [x] **Step 1: Write the failing tests** in `tests/integration/Services/LotWriterTest.php` (extends `IntegrationTestCase`, `USER_ID = 616161`)

Add private helpers. `lots( int $user_id ): array` returns the `lots` rows ordered by id. `allocations_for( int $ledger_id ): array`
returns the `lot_allocations` rows ordered by id. `assert_invariant( int $user_id )` asserts both halves of spec criterion 5.

```php
public function test_earn_opens_a_lot(): void {
    $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 1, 100, '2026-03-14 09:00:00' ) );

    $row  = $this->ledger_rows( self::USER_ID )[0];
    $lots = $this->lots( self::USER_ID );
    $this->assertCount( 1, $lots );
    $this->assertSame( [ '100', '100', 'open', $row['id'], '2026-03-14 09:00:00' ],
        [ $lots[0]['points'], $lots[0]['remaining'], $lots[0]['status'], $lots[0]['ledger_id'], $lots[0]['created_at'] ] );
}

public function test_negative_row_consumes_the_oldest_lot_first(): void {
    $service = $this->ledger_service();
    $service->earn( $this->earn_draft( self::USER_ID, 1, 100, '2026-01-01 00:00:00' ) );
    $service->earn( $this->earn_draft( self::USER_ID, 2, 50, '2026-02-01 00:00:00' ) );
    $deduct = $service->adjust( self::USER_ID, -120, 'test', 0 );

    $lots = $this->lots( self::USER_ID );
    $this->assertSame( [ [ '0', 'consumed' ], [ '30', 'open' ] ],
        array_map( fn( $l ) => [ $l['remaining'], $l['status'] ], $lots ) );
    $this->assertSame( [ '100', '20' ], array_column( $this->allocations_for( $deduct->get_id() ), 'points' ) );
    $this->assert_invariant( self::USER_ID );
}

public function test_earn_pays_the_deficit_against_itself(): void {
    $service = $this->ledger_service();
    $service->adjust( self::USER_ID, -40, 'test', 0 );
    $service->earn( $this->earn_draft( self::USER_ID, 1, 100 ) );

    $earn_id = (int) $this->ledger_rows( self::USER_ID )[1]['id'];
    $lot     = $this->lots( self::USER_ID )[0];
    $this->assertSame( [ '100', '60', 'open' ], [ $lot['points'], $lot['remaining'], $lot['status'] ] );
    $this->assertSame( [ [ $lot['id'], (string) $earn_id, '40' ] ],
        array_map( fn( $a ) => [ $a['lot_id'], $a['ledger_id'], $a['points'] ], $this->allocations_for( $earn_id ) ) );
    $this->assert_invariant( self::USER_ID );
}

public function test_invariant_holds_after_a_mixed_sequence(): void {
    $service = $this->ledger_service();
    $earn    = $service->earn( $this->earn_draft( self::USER_ID, 1, 100 ) )->get_entry();
    $service->earn( $this->earn_draft( self::USER_ID, 2, 50 ) );
    $refund  = $service->refund_earn( $earn, 80, ReferenceType::REFUND, 9001 )->get_entry();
    $service->adjust( self::USER_ID, -100, 'test', 0 );          // balance -30
    $service->restore_refund( $refund, 9001 );                   // +80 pays 30, lot keeps 50
    $service->adjust( self::USER_ID, 30, 'test', 0, false );     // correction +30

    $this->assertSame( 80, (int) $this->customer_row( self::USER_ID )['balance'] );
    $this->assert_invariant( self::USER_ID );
}
```

- [x] **Step 2: Run them**

Run: `composer test:integration -- --filter LotWriterTest`
Expected: FAIL, because no lots are written.

- [x] **Step 3: Implement `LotRepository`**: `open_lots_fifo`, `insert_lot`, `insert_allocation` and `set_remaining` from the README contract

It extends `AbstractRepository` (table slug `lots`). Reads take no `FOR UPDATE`, because the customer lock serialises writers.

- [x] **Step 4: Implement `LotWriter::record( LedgerEntry $row, Customer $before ): void`**

The signature doesn't fix the rule:
- **Positive row:** `paid = $before->get_balance() < 0 ? min( -balance, points ) : 0`. Insert the lot with `remaining = points − paid`,
  status `consumed` if 0 else `open`, `expires_at` NULL (Task 3 replaces this with `ExpiryRules::lot_expires_at()`), and
  `created_at` = the row's. If `paid > 0`, insert the allocation `( lot, row id, paid )`.
- **Negative row:** `take = min( |points|, max( $before->get_balance(), 0 ) )`. Walk `open_lots_fifo()`, allocating
  `min( take_left, remaining )` per lot and calling `set_remaining()`. A lot that reaches 0 gets `expired` when the row is
  `LedgerType::EXPIRE`, else `consumed`. The shortfall beyond `take` is the deficit, and nothing records it.

- [x] **Step 5: Wire it into `LedgerService`**

In `__construct`, `$this->lots = new LotWriter( new LotRepository(), $customers );` (no constructor parameter, so the four call
sites don't change). In `append()`, call `$this->lots->record( $persisted, $customer )` after `apply_aggregates()` and before
`announce()`. Add to the class docblock: "Lots and allocations are written only here, through LotWriter, which nothing else constructs."
In `docs/architecture.md` § Server layers and write ownership, add `lots` and `lot_allocations` to `LedgerService`'s writes (via `LotWriter`).

- [x] **Step 6: Run the tests**

Run: `composer test:integration -- --filter LotWriterTest`
Expected: PASS (4 tests).
Run: `composer test:integration`
Expected: no new failures against `dev/edd`'s result. The earning and reversal flows now also write lots.

- [ ] **Step 7: Lint and commit**

Run: `composer lint -- includes/Repositories/LotRepository.php includes/Services/LotWriter.php includes/Services/LedgerService.php`
Expected: no errors.
Stage: those three files, `docs/architecture.md`, `tests/integration/Services/LotWriterTest.php`
Message: `feat(ledger): write lots and allocations with every ledger row`

### Task 3: Expiry settings, rules and `customers.expires_at`

**Files:**
- Modify: `includes/Helpers/SettingsHelper.php` (defaults, `schema()`, `limits_valid()`, `coerce()`)
- Modify: `apps/admin/src/lib/schema/settings.schema.ts`
- Modify: `includes/Utils/Dates.php`
- Create: `includes/Services/ExpiryRules.php`
- Modify: `includes/Services/LotWriter.php` (expiry sync at the end of `record()`, `lot_expires_at()` for new lots; third constructor parameter `CustomerRepository`)
- Modify: `includes/Repositories/CustomerRepository.php` (`set_expires_at`, `recompute_inactivity_expiry`)
- Create: `includes/Engine/Expiry/ExpiryRecompute.php`
- Modify: `includes/Controllers/SettingsRestController.php` (`save_settings()`)

**Interfaces:**
- Consumes: `LotWriter::record` (Task 2).
- Produces: README contract `Dates`, `ExpiryRules`, the settings table, `set_expires_at`, `recompute_inactivity_expiry` and `ExpiryRecompute::run`.

- [x] **Step 1: Add the `expiry` group to `SettingsHelper`**

Add the defaults and schema types from the README settings table. Add `coerce()` cases:
- `expiry_days`: clamp to 1–3650.
- `reminder_days`: clamp to 0–365.
- `expiry_months`: clamp to 1–120.
- `expiry_model`: `batches` or else `inactivity`.

`limits_valid()` also returns false for an out-of-range `expiry_days`, `reminder_days` or `expiry_months`, and for an `expiry_model` outside the two values.
Mirror the group in `settings.schema.ts`. Its comment says it mirrors `SettingsHelper`, and `model` is
`z.enum(['inactivity', 'batches'])`.

- [x] **Step 2: Add `Dates::add_days()` and `Dates::add_months()`**

`add_months` clamps the day to the target month's last day. `add_months( '2026-01-31 09:00:00', 1 )` returns `2026-02-28 09:00:00`.

- [x] **Step 3: Implement `ExpiryRules`**, as in the README contract

`customer_expires_at()` returns NULL when expiry is off or `$balance <= 0`. Otherwise:
- under inactivity, `add_days( $last_activity_at ?? $joined_at, inactivity_days() )`;
- under batches, `$earliest_lot_expiry`.

The `joined_at` fallback is a plan decision: a balance from admin grants alone would otherwise never expire.

- [x] **Step 4: Sync `customers.expires_at` at the end of `LotWriter::record()`**

Compute `ExpiryRules::customer_expires_at()` with:
- the row's `balance_after`;
- the row's `created_at` when `LedgerType::is_activity()`, else `$before->get_last_activity_at()`;
- `$before->get_joined_at()`;
- NULL as the earliest lot expiry (Phase 3 fills it in).

Call `CustomerRepository::set_expires_at()` only when the value differs from `$before->get_expires_at()`. New lots take `ExpiryRules::lot_expires_at( created_at )`.

- [x] **Step 5: Recompute everyone on save**

`CustomerRepository::recompute_inactivity_expiry( ?int $days )` runs one `UPDATE`. It sets `expires_at = DATE_ADD( COALESCE( last_activity_at, joined_at ), INTERVAL %d DAY )`
where `balance > 0`, and `expires_at = NULL` everywhere else (all rows when `$days` is null).

`ExpiryRecompute::run()` calls it with `ExpiryRules::enabled() && MODEL_INACTIVITY === ExpiryRules::model() ? inactivity_days() : null`.
Phase 3 adds the batch branch.

In `SettingsRestController::save_settings()`, call `ExpiryRecompute::run()` after `update_settings()` when the payload has the `expiry` key.

- [x] **Step 6: Verify on the local site**

Done 2026-10-13 without WP-CLI, which isn't installed. A scratchpad script loaded `wp-load.php` and compared `Dates::add_months()` with MySQL `DATE_ADD` (5/5 equal, leap year included). The local site has no customers, so the sync and recompute were checked with a throwaway, uncommitted test run through the integration harness on scratch tables (17 assertions: criterion 6, the `joined_at` fallback, zero balance, recompute on/shorter/off, range validation, REST save + 400).

Run: `wp eval 'YayLoyalty\Helpers\SettingsHelper::update_settings(["expiry"=>["enabled"=>true,"inactivity_days"=>365]]); YayLoyalty\Engine\Expiry\ExpiryRecompute::run();'`
Then: `wp db query "SELECT user_id, last_activity_at, joined_at, balance, expires_at FROM wp_yayloyalty_customers LIMIT 5"`
Expected: every row with `balance > 0` has `expires_at` = (`last_activity_at`, or `joined_at` when NULL) + 365 days, and every other row NULL.
Run the same with `"enabled"=>false`.
Expected: every `expires_at` is NULL.
Run: `wp eval 'echo YayLoyalty\Utils\Dates::add_months("2026-01-31 09:00:00", 1);'`
Expected: `2026-02-28 09:00:00`.
Run: `composer test:integration -- --filter LotWriterTest`
Expected: PASS (expiry is off by default, so the lots are unchanged).

- [ ] **Step 7: Lint and commit**

Run: `composer lint -- <the PHP files above>` and `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`
Expected: no errors.
Stage: the files above
Message: `feat(expiry): add expiry settings and keep customers' expiry dates`

### Task 4: Expire due customers daily

**Files:**
- Modify: `includes/Services/LedgerService.php` (`REASON_NOT_DUE`, `expire()`, the docblock's construction list)
- Modify: `includes/Services/ExpiryRules.php` (`expiring_points()`)
- Modify: `includes/Repositories/CustomerRepository.php` (`due_for_expiry`)
- Create: `includes/Engine/Expiry/ExpiryJob.php`
- Modify: `includes/YayLoyalty.php` (`plugin_init()`: `( new ExpiryJob( … ) )->register()`)
- Modify: `includes/Engine/Emails/Emails.php` (`POINTS_EXPIRED_ACTION`, `add_email_actions()`, relay on `yayloyalty_ledger_recorded`)
- Modify: `includes/Engine/Emails/PointsExpiredEmail.php` (extends `LedgerEntryEmail`)
- Test: `tests/integration/Services/LotWriterTest.php`

**Interfaces:**
- Consumes: Task 3 `ExpiryRules`, `customers.expires_at`.
- Produces: README contract `LedgerService::expire`, `ExpiryRules::expiring_points`, `due_for_expiry`, `ExpiryJob` and `POINTS_EXPIRED_ACTION`.

- [ ] **Step 1: Write the failing tests** in `LotWriterTest`

`setUp` saves `get_option( SettingsHelper::OPTION_SETTINGS, null )`, and `tearDown` restores it (options are not moved by the scratch prefix).

```php
public function test_expire_empties_every_lot_once(): void {
    SettingsHelper::update_settings( [ 'expiry' => [ 'enabled' => true, 'inactivity_days' => 1 ] ] );
    $service = $this->ledger_service();
    $service->earn( $this->earn_draft( self::USER_ID, 1, 300, Dates::offset( -3 * DAY_IN_SECONDS ) ) );
    $service->earn( $this->earn_draft( self::USER_ID, 2, 200, Dates::offset( -3 * DAY_IN_SECONDS ) ) );
    $due = (string) $this->customer_row( self::USER_ID )['expires_at'];

    $first  = $service->expire( self::USER_ID, $due );
    $second = $service->expire( self::USER_ID, $due );

    $this->assertSame( Outcome::AWARDED, $first->get_status() );
    $this->assertSame( [ LedgerType::EXPIRE, LedgerSource::EXPIRY, -500 ],
        [ $first->get_entry()->get_type(), $first->get_entry()->get_source(), $first->get_entry()->get_points() ] );
    $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
    $this->assertSame( [ [ '0', 'expired' ], [ '0', 'expired' ] ],
        array_map( fn( $l ) => [ $l['remaining'], $l['status'] ], $this->lots( self::USER_ID ) ) );
    $this->assertSame( 0, (int) $this->customer_row( self::USER_ID )['balance'] );
    $this->assertNull( $this->customer_row( self::USER_ID )['expires_at'] );
    $this->assert_invariant( self::USER_ID );
}

public function test_expire_skips_a_customer_no_longer_due(): void {
    SettingsHelper::update_settings( [ 'expiry' => [ 'enabled' => true, 'inactivity_days' => 365 ] ] );
    $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 1, 100 ) );

    $outcome = $this->ledger_service()->expire( self::USER_ID, (string) $this->customer_row( self::USER_ID )['expires_at'] );

    $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
    $this->assertSame( LedgerService::REASON_NOT_DUE, $outcome->get_reason() );
    $this->assertSame( [ 100 ], $this->ledger_points( self::USER_ID ) );
}
```

- [ ] **Step 2: Run them**

Run: `composer test:integration -- --filter 'LotWriterTest::test_expire'`
Expected: FAIL, `expire()` is undefined.

- [ ] **Step 3: Implement `ExpiryRules::expiring_points()` and `LedgerService::expire()`** (README contract)

`expire()` builds an `EXPIRE` / `LedgerSource::EXPIRY` / `ReferenceType::USER` draft and goes through `write_keyed()`. The
`$points_under_lock` callback returns 0 (which skips with `REASON_NOT_DUE`) when the locked customer's `expires_at` ≠ `$due_at` or > now.
Otherwise it returns `-ExpiryRules::expiring_points( $customer, Dates::now() )`. `write_keyed()` now calls `$points_under_lock( $customer )` with the locked `Customer`.
The existing closures take no parameter and ignore it. Add `Engine\Expiry\ExpiryJob::__construct()` to the docblock's list of construction sites.

- [ ] **Step 4: Run them**

Run: `composer test:integration -- --filter LotWriterTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Implement `CustomerRepository::due_for_expiry()` and `ExpiryJob`**

`register()` does the following:
- On `init`, when `function_exists( 'as_has_scheduled_action' )` and `! as_has_scheduled_action( HOOK, [], GROUP )`, it calls
  `as_schedule_recurring_action( time(), DAY_IN_SECONDS, HOOK, [], GROUP )`.
- It hooks `run()` on `HOOK`.

`run()` returns at once when `! ExpiryRules::enabled()`. Otherwise, for each `due_for_expiry( Dates::now(), PAGE )` row, it calls
`expire( user_id, expires_at )`. A `\Throwable` goes to `Log::error( 'ExpiryJob', … )`, and the loop continues. When a full page comes back,
it calls `as_enqueue_async_action( HOOK, [], GROUP )`. Its docblock names the hook.

- [ ] **Step 6: Relay the expired email**

`Emails` adds `POINTS_EXPIRED_ACTION`, lists it in `add_email_actions()`, and in its constructor hooks `yayloyalty_ledger_recorded`
with `relay_expiry( LedgerEntry $entry )`, which fires the action for `LedgerType::EXPIRE` rows only.

`PointsExpiredEmail` extends `LedgerEntryEmail` and hooks `POINTS_EXPIRED_ACTION . '_notification'`:
- `accepts()` takes `EXPIRE` rows only.
- `amounts()` returns `points`, `points_label` and `balance`, as `PointsAdjustedEmail` does.
- `build_values()` returns `balance_values()`.
- `prepare_preview()` uses a −50 `EXPIRE` / `EXPIRY` row.

The templates stay as they are.

- [ ] **Step 7: Verify the job**

Run: `wp action-scheduler list --hook=yayloyalty_expire_points --status=pending --format=count` after loading any page.
Expected: `1`.
Run: `composer lint -- <the PHP files above>`
Expected: no errors.

- [ ] **Step 8: Commit**

Stage: the files above
Message: `feat(expiry): expire inactive balances daily`

### Task 5: Settings → Expiry tab (Free)

**Files:**
- Create: `apps/admin/src/pages/settings/ExpirySettingsPage.tsx`
- Create: `apps/admin/src/pages/settings/expiry-settings.form.ts`
- Create: `apps/admin/src/pages/settings/expiry-settings.copy.ts`
- Create: `apps/admin/src/pages/settings/ExpiryInactivityCard.tsx`
- Create: `apps/admin/src/pages/settings/ExpiryProUpsellCard.tsx`
- Modify: `apps/admin/src/router.tsx`, `apps/admin/src/components/layout/SettingsSideNav.tsx`, `apps/admin/src/pages/settings/EmailsSettingsPage.tsx:101`

**Interfaces:**
- Consumes: the `expiry` settings group (Task 3), `useCustomersQuery( { perPage: 1, expiringSoon: '90' } )`.
- Produces: route `/settings/expiry`.

- [ ] **Step 1: Form module** `expiry-settings.form.ts`

`expirySettingsFormSchema = settingsSchema.shape.expiry.pick( { enabled, inactivity_days, reminder_days } )`, plus
`expirySettingsDefaults( settings )` and `buildExpirySettingsUpdate( values, dirty ): SettingsUpdate`. The latter returns
`{ expiry: { …edited fields } }`, as `general-settings.form.ts` does.

- [ ] **Step 2: Page and cards**

`ExpirySettingsPage` copies the `GeneralSettingsPage` pattern (`useForm` + `values` + `keepDirtyValues`, `useEditorSave`, `UnsavedChangeDialog`).
It renders `ExpiryInactivityCard`, then `IS_PRO_BUILD ? null : <ExpiryProUpsellCard />` (Phase 2 and 3 add Pro sections behind the same flag), then Save.

`ExpiryInactivityCard` follows the design's free artboard:
- the "Expire points after inactivity" switch, with "When off, balances are kept until they are spent.";
- "Expire after [n] days";
- "Remind before expiry [n] days", with a link to `/settings/emails`;
- the dated sentence for today's date in the site's locale. It updates as the fields change.
- "{N} customers have points expiring in the next 90 days. View them", linking to `/customers?expiring_soon=90`. N is the query's
  `total`, and the line is hidden while the toggle is off.

`ExpiryProUpsellCard` shows the design's "What Pro adds to expiry" list without the spend-order line, plus `<ProGate />`. All strings
go in `expiry-settings.copy.ts`.

- [ ] **Step 3: Route and nav**

Add `{ path: 'expiry', element: <ExpirySettingsPage /> }` and point the index and `*` redirects at `/settings/expiry`.
Add `{ to: '/settings/expiry', label: __('Expiry', 'yayloyalty') }` as the first entry of `SETTINGS_NAV`. In `EmailsSettingsPage.tsx`, change the link to `/settings/expiry`.

- [ ] **Step 4: Type-check and lint**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`
Expected: no errors.
Run: `pnpm --filter @yayloyalty/admin-page exec eslint src/pages/settings/ExpirySettingsPage.tsx src/pages/settings/ExpiryInactivityCard.tsx src/pages/settings/ExpiryProUpsellCard.tsx src/pages/settings/expiry-settings.form.ts src/pages/settings/expiry-settings.copy.ts src/router.tsx src/components/layout/SettingsSideNav.tsx`
Expected: no errors.

- [ ] **Step 5: Verify in the admin** (`./scripts/run.sh dev pro`)

Open: `/wp-admin/admin.php?page=yayloyalty#/settings`
Expected: it lands on Expiry, which is the first tab.
Action: turn expiry on, set 200 days, save, reload.
Expected: the values persist. The sentence reads "… on <today> will lose their balance on <today + 200 days> …". "View them" opens the customers list with the "expiring in 90 days" filter applied.
Action: change a field, then click another tab.
Expected: the unsaved-changes dialog appears.

- [ ] **Step 6: Phase exit: end-to-end (spec criterion 14, inactivity)**

On the local Pro site:
1. Set Expiry on, 1 day, and save.
2. Run `wp eval '( new YayLoyalty\Services\LedgerService( new YayLoyalty\Repositories\LedgerRepository(), new YayLoyalty\Repositories\CustomerRepository(), new YayLoyalty\Repositories\CampaignRepository() ) )->adjust( <user>, 500, "e2e", 1 );'`.
3. Run `wp db query "UPDATE wp_yayloyalty_customers SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 2 DAY, expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE user_id = <user>"`.
4. Run `wp action-scheduler run --hooks=yayloyalty_expire_points --force`.

Expected:
- the customer's ledger tab shows one `expire −500` row ("Points expired" on the storefront history);
- the balance is 0;
- `SELECT status FROM wp_yayloyalty_lots WHERE user_id = <user>` returns only `expired`;
- WooCommerce → Status → Logs has no new `yayloyalty` error.

Then run `./scripts/run.sh release pro --no-prompt` followed by `./scripts/run.sh release lite --no-prompt`.
Expected: both validate. `validate-build.php` reports no Pro name or sentinel in the Lite zip. Afterwards, run `composer install`.

- [ ] **Step 7: Commit**

Stage: the SPA files above
Message: `feat(settings): add the Expiry tab`
