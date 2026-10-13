# Points Lots Schema Design

**Status:** superseded by [2026-10-13-points-expiry-design.md](2026-10-13-points-expiry-design.md)
**Related:** deleted design `docs/data-model/future-tables.md` (in `d23ff178^`); `docs/decisions/2026-10-01-ledger-service-sole-writer.md`

## Goal

Install two Pro tables, `yayloyalty_lots` and `yayloyalty_lot_allocations`, whose shape supports per-earning points
expiry without mutating the append-only ledger. This spec ships the tables and their reliable installation; no code
writes to them yet.

## Context

- The ledger (`includes/Database/Schema/LedgerTable.php`) is append-only; `LedgerService` is its only writer (hard rule 5).
- Rows that add points: `earn`, positive `adjust`/`correction`, `restore_refund` (an `ADJUST` row), later `refund_redeem`.
  Rows that remove: `refund_earn`, negative `adjust`/`correction`, later `redeem` (stub) and `expire` (no job yet).
- `customers.balance` is signed: a clawback can push it below 0.
- `customers.expires_at` (indexed) exists for Lite inactivity expiry; nothing writes it yet.
- `Installer::schemas()` applies `yayloyalty_table_schemas` (unhooked today). `Installer::maybe_upgrade()` (plugins_loaded
  priority 20) re-runs `install()` only when `yayloyalty_db_version` ≠ `YAYLOYALTY_VERSION`.
- `YayLoyaltyPro::boot()` runs inside `plugin_init` (plugins_loaded priority 10) and only when WooCommerce is active.
  Activation (`ActDeact::activate`) calls `install()` before `plugin_init`, so Pro's filters are not attached then.
- Drawer wire shape waiting for lots: `expiry.batches` in `apps/admin/src/lib/schema/customer.schema.ts:34-55`.

## Scope

**In:**
- `LotsTable` and `LotAllocationsTable` schema classes in `includes/Pro/Database/Schema/`.
- `YayLoyaltyPro::boot()` hooks `yayloyalty_table_schemas` to add them.
- Installer change: track installed slugs so Pro tables appear on activation, Lite→Pro swap and installs without WooCommerce.
- Docs: `docs/architecture.md` § Data and upgrades, a decision record.
- Integration test `tests/integration/InstallerProTablesTest.php`.

**Out:**
- Any writer: lot creation, FIFO consumption, deficit payoff, refund restores (behaviour spec, next).
- The seam that lets Pro write lots inside `LedgerService`'s transaction. `announce()` fires after commit, so it
  can't serve; the behaviour spec decides the seam and likely adds its own decision record.
- The daily lot-expiry Action Scheduler job, opening-lot backfill job, expiry settings, reminder emails,
  drawer batches panel, Reset expiry action, `customers.expires_at` maintenance.
- Lite inactivity expiry.
- Rebuilding stale lots after a Pro→Lite→Pro round trip.
- Uninstall routine (none exists; tables stay behind like every other table).

## Acceptance criteria

1. On a Pro site, after `Installer::install()`, `SHOW CREATE TABLE {prefix}yayloyalty_lots` and `…_lot_allocations`
   match the DDL below, and `Installer::verify()` returns no problems for either table.
2. On a Lite build, neither table is created and `scripts/validate-build.php` passes (no Pro names outside `includes/Pro/`).
3. Fresh Pro activation: after activation plus one more page load, both tables exist.
4. Lite→Pro swap at the same `YAYLOYALTY_VERSION`: on the first request after the swap, both tables exist.
5. `yayloyalty_db_tables` holds the slugs the last `install()` created; `maybe_upgrade()` runs `install()` when any
   slug in `Installer::schemas()` is missing from it, even if the version matches.
6. Two `lots` rows with `ledger_id` NULL insert without a unique-key error; two with the same non-NULL `ledger_id` fail.
7. Two `lot_allocations` rows with the same `(lot_id, ledger_id)` fail with a duplicate-key error.
8. **Tests:** `tests/integration/InstallerProTablesTest.php` (integration suite, accepted by the user 2026-10-09):
   `install()` + `verify()` report no problems for `lots` and `lot_allocations`; with `yayloyalty_db_version` equal to
   `YAYLOYALTY_VERSION` and `lots` absent from `yayloyalty_db_tables`, `maybe_upgrade()` runs `install()` and creates the table.
9. End-to-end verification: on the local Pro site, deactivate + activate the plugin, load wp-admin once, then
   `wp db query "SHOW TABLES LIKE '%yayloyalty_lot%'"` lists both tables and
   `wp eval 'var_dump( \YayLoyalty\Database\Installer::verify() );'` prints an empty array.

## Constraints

- PHP 7.4 syntax in product code (hard rule 4).
- Lite names Pro only at the guarded boot call (hard rule 2); the schema classes live under `includes/Pro/`.
- dbDelta rules from `AbstractTableSchema`: `KEY name (...)` / `UNIQUE KEY name (...)`, two spaces after `PRIMARY KEY`, `ENGINE=InnoDB`.
- All datetimes UTC (`Utils\Dates`).
- Points are integers; no money columns.

## Approach

**Chosen:** Pro registers both tables through `yayloyalty_table_schemas`; the Installer tracks installed slugs.

**Architecture:** Pro adds two `AbstractTableSchema` subclasses via the existing filter. Because the filter is absent
during activation and without WooCommerce, the version check alone would stamp an install that lacks them. The
Installer therefore also stores the slugs it installed and re-installs when a registered slug is missing.

**Components:**
- `includes/Pro/Database/Schema/LotsTable.php` — DDL, expected columns and indexes for `lots`.
- `includes/Pro/Database/Schema/LotAllocationsTable.php` — same for `lot_allocations`.
- `includes/Pro/YayLoyaltyPro.php` — `add_filter( 'yayloyalty_table_schemas', [ self::class, 'add_table_schemas' ] )`.
- `includes/Database/Installer.php` — `OPTION_DB_TABLES = 'yayloyalty_db_tables'`; `install()` writes the slug list
  (before the version stamp); `maybe_upgrade()` and its in-lock re-check also run when a slug is missing.

**Data and storage:**

`{prefix}yayloyalty_lots` — one row per positive ledger row while Pro lot expiry is on, plus opening lots.

| Column | Type | Meaning |
|---|---|---|
| `id` | bigint unsigned NOT NULL AUTO_INCREMENT | PK |
| `user_id` | bigint unsigned NOT NULL | Customer |
| `ledger_id` | bigint unsigned DEFAULT NULL | Positive ledger row the lot came from; NULL = opening lot (backfilled balance) |
| `points` | bigint unsigned NOT NULL | Original size |
| `remaining` | bigint unsigned NOT NULL | `points − SUM(allocations.points)` |
| `expires_at` | datetime DEFAULT NULL | UTC; NULL = never expires |
| `status` | varchar(10) NOT NULL | `open`, `consumed`, `expired`; flipped only by the writer |
| `created_at` | datetime NOT NULL | UTC; equals the ledger row's `created_at` |
| `updated_at` | datetime NOT NULL | UTC; changes with `remaining` |

Indexes: `PRIMARY KEY (id)`, `UNIQUE KEY uniq_ledger_id (ledger_id)`, `KEY idx_user_open (user_id,status,expires_at)`,
`KEY idx_status_expires (status,expires_at)`.

`{prefix}yayloyalty_lot_allocations` — append-only; never updated or deleted.

| Column | Type | Meaning |
|---|---|---|
| `id` | bigint unsigned NOT NULL AUTO_INCREMENT | PK |
| `lot_id` | bigint unsigned NOT NULL | Lot |
| `ledger_id` | bigint unsigned NOT NULL | Row that took points out (redeem, expire, refund_earn, negative adjust/correction, or the earlier negative row a deficit payoff settles) or returned them (refund_redeem) |
| `points` | bigint NOT NULL | Signed, never 0: positive = taken, negative = returned |
| `created_at` | datetime NOT NULL | UTC |

Indexes: `PRIMARY KEY (id)`, `UNIQUE KEY uniq_lot_ledger (lot_id,ledger_id)`, `KEY ledger_id (ledger_id)`.

Invariants the future writer must keep (recorded here so the behaviour spec inherits them):
- per lot `0 ≤ remaining ≤ points`; `remaining = points − SUM(allocations.points)`;
- `SUM(remaining WHERE status='open') = max(customers.balance, 0)`;
- FIFO order `expires_at IS NULL, expires_at, id` (never-expiring lots last);
- lot and allocation writes happen in the same transaction as their ledger row, under the customer lock.

New option: `yayloyalty_db_tables` (autoloaded array of slugs). Existing installs: the next request finds the option
missing, runs `install()`, and creates the Pro tables. Uninstall: none (unchanged).

**Integration points:** filter `yayloyalty_table_schemas` (first consumer); option `yayloyalty_db_tables`.

**UI:** None.

**Edge cases and failure modes:**
- Activation: `install()` runs without Pro schemas and stamps the version; the next request sees `lots` missing
  from `yayloyalty_db_tables` and installs it.
- WooCommerce inactive on a Pro site: Pro doesn't boot, `schemas()` lacks lots; the slug check only looks for
  registered slugs missing from the list, so it does not loop. Tables arrive once WooCommerce is active.
- Pro→Lite downgrade: tables and rows stay; Lite neither verifies nor writes them. A Lite `install()` overwrites the
  slug list without Pro slugs, so a later Pro upgrade re-runs `install()` (dbDelta on an existing table is a no-op).
- Multisite: the option and tables are per blog; `Table::name()` already reads the prefix per call.
- Concurrent first requests: covered by the existing `yayloyalty_upgrading` lock and the in-lock re-check.

**Decision record:** the implementing PR adds `docs/decisions/2026-10-xx-pro-tables-via-schema-filter.md`: Pro
registers its tables through `yayloyalty_table_schemas`, and the Installer re-installs when a registered table slug
was never installed.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Tables in core `Installer::schemas()` for both builds | User chose to keep Pro tables off Lite sites |
| Lots only for `earn` rows | `SUM(remaining)` drifts from `balance`; admin grants never expire |
| Mutable allocations | Loses history; `remaining` can't be audited |
| Lots ignore the deficit | Expiry could remove points the customer doesn't hold |
| Replay the ledger for backfill / no backfill | Heavy on large stores / permanent drift |
| Pro runs its own dbDelta with `yayloyalty_pro_db_version` | Duplicates install, lock and verify logic |
| Fingerprint `yayloyalty_db_version` | Option stops meaning "plugin version" |
| Drop `status` | Expired vs consumed only derivable via allocations |

## Rollout

Ships in the next version bump. On every existing install, Lite included, the first request after updating finds
`yayloyalty_db_tables` missing and runs `install()` once; on Pro that creates the tables. Empty tables; no merchant-visible change. No release-note item beyond "internal".

## Open questions

None.

