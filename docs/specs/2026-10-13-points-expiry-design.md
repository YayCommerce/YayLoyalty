# Points Expiry Design

**Status:** accepted 2026-10-13
**Related:** supersedes `docs/specs/2026-10-09-points-lots-schema-design.md`; design file F11 "Expiry" (`YayLoyalty Settings.dc.html`, artboards expiry-free and expiry-pro); `docs/decisions/2026-10-01-ledger-service-sole-writer.md`

## Goal

Merchants can make loyalty points expire. In Free, a customer's whole balance expires after a period without earning or redeeming.
In Pro, each earning instead expires a set number of months after it was earned. Customers get reminder emails
before anything expires, and every expiry is an `expire` row in the ledger.

## Context

- Commit `64c230d` on `paul/feat/expires-point` (not merged into `dev/edd`) added `LotsTable` and `LotAllocationsTable`
  under `includes/Pro/Database/Schema/`, installed through `yayloyalty_table_schemas` plus `yayloyalty_db_tables`
  slug tracking in `includes/Database/Installer.php`, with decision record `2026-10-09-pro-tables-via-schema-filter`
  (`Proposed`) and `tests/integration/Pro/InstallerProTablesTest.php`. Nothing writes to the tables.
- The plugin has not been released, so no store holds balances that predate lots. Local and staging sites reset their data.
- `LedgerService` (`includes/Services/LedgerService.php`) is the only writer of ledger rows and balances. `append()` runs
  under the customer row lock inside `Transaction::run()`. `apply_aggregates()` passes `expires_at = null`
  ("Expiry needs the lots table").
- `LedgerType::EXPIRE` and `LedgerSource::EXPIRY` exist, but no method writes them.
- The `customers` table has `last_activity_at`, `reminder_sent_at` and `expires_at` (indexed). `CustomerRepository::apply_delta()`
  sets `last_activity_at` and clears `reminder_sent_at` on activity rows (earn, redeem). Nothing writes `expires_at`.
- Readers of `customers.expires_at` already exist: the points card (`Engine/Storefront/templates/points-card.php`), the customers-list
  filter `expiring_soon` (`CustomerFilters::EXPIRY_RANGES` = `off|30|90|none`, `Controllers/CustomerRowFilters.php`)
  and the list's `expires` sort.
- Emails exist but are never triggered: `PointsExpiringSoonEmail`, `PointsExpiredEmail` (Lite) and `ExpiringFinalReminderEmail` (Pro).
  The relay pattern is `Engine/Emails/Emails.php`: `do_action( <ACTION>, $id )`, listed in `add_email_actions()`, and the email
  hooks `<ACTION>_notification`.
- Settings live in `yayloyalty_settings` through `Helpers/SettingsHelper.php` (defaults + `schema()`). The SPA tabs come from
  `SETTINGS_NAV` (`apps/admin/src/components/layout/SettingsSideNav.tsx`) and `router.tsx`. The Emails tab links
  "Expiry reminder timing" to `/settings/general`, which has no such control.
- Nothing in the plugin schedules work yet. WooCommerce bundles Action Scheduler, and the plugin boots only with WooCommerce active.

## Scope

**In:**
- Lots move to core: both builds create both tables and write lots.
- `LotWriter`: one lot per positive ledger row, FIFO allocations per negative row, deficit payoff, and upkeep of
  `customers.expires_at`.
- `LedgerService::expire()` and a daily Action Scheduler job that expires due points and sends reminders.
- An Expiry settings tab: the Free inactivity form and Pro's model choice, batch months, second reminder and reminder floor.
- Triggering `PointsExpiringSoonEmail`, `PointsExpiredEmail` and (Pro) `ExpiringFinalReminderEmail`.

**Out (backlog items, added once this spec is accepted):**
- The confirmation modal and 30-day grace when the window is shortened.
- Exemptions (the list and the drawer action), the liability projection, bulk extend expiry, the Reset-expiry action.
- The drawer `expiry` payload and batches panel (the formatter keeps sending `null`).
- `refund_redeem` returning points to their original lots (`redeem()` is still a stub).
- The design's "Spend order" control. Spend order is fixed to oldest first.
- Any backfill of lots for balances that predate this change (none exist in production).
- Per-tier expiry windows (the design rules them out).

## Acceptance criteria

1. Both builds: after `Installer::install()`, `{prefix}yayloyalty_lots` and `{prefix}yayloyalty_lot_allocations` exist with the DDL
   from the superseded spec (unchanged), and `Installer::verify()` returns `[]`. `yayloyalty_db_tables` is no longer written or read.
2. An earn of 100 creates one lot: `points = 100`, `remaining = 100`, `status = open`, `ledger_id` = the earn row,
   `created_at` = the row's `created_at`.
3. With open lots of 100 (older) and 50, a −120 row writes allocations of 100 and 20 against those lots. The older lot reads
   `remaining 0, consumed` and the newer one `remaining 30, open`.
4. At balance −40, an earn of 100 creates a lot with `points 100` and `remaining 60`, plus one allocation `(lot, the earn row's id, 40)`.
5. Invariant after any sequence of earn, refund_earn, adjust ±, correction ±, restore_refund and expire:
   `SUM(remaining WHERE user_id = u AND status = 'open') = max(customers.balance, 0)`, and for every lot
   `remaining = points − SUM(lot_allocations.points WHERE lot_id = lot)`.
6. Inactivity model, `enabled = true`, `inactivity_days = 365`: after an earn on 2026-10-13 10:00 UTC, `customers.expires_at`
   = `2027-10-13 10:00:00`. With `enabled = false` it is NULL.
7. Batch model, `batch_months = 12`: a lot created on `2026-03-14 09:00:00` gets `expires_at = 2027-03-14 09:00:00`, and
   one created on `2026-01-31 09:00:00` gets `2027-01-31 09:00:00`. `batch_months = 1` on `2026-01-31` gives `2026-02-28`, not March.
   `customers.expires_at` = the earliest open lot's `expires_at`.
8. The daily job on a customer whose `expires_at` ≤ now writes exactly one `expire` row (source `expiry`). Under the inactivity
   model it is for −balance, and under the batch model for −SUM(remaining of open lots with `expires_at` ≤ now). The lots it
   empties read `expired`. Running the job twice writes no second row (idempotency key).
9. Reminders: under the inactivity model, `reminder_days = 30` and `expires_at` 29 days ahead, one job run sends
   "Points expiring soon" once and stamps `reminder_sent_at`. A second run sends nothing. Pro with
   `final_reminder_days = 7` also sends "Final reminder" once when 7 days or less remain. Pro with `reminder_min_points = 100`
   sends neither email to a customer whose expiring points are below 100.
10. Saving any expiry setting recomputes every lot's `expires_at` and every customer's `expires_at` under the new rules
    before the save request returns.
11. Model switch: saving `model = batches` succeeds. A later save with `model = inactivity` fails with HTTP 400
    `yayloyalty_expiry_model_one_way`. In Lite, the tab shows no model cards, and the stored `model` value is kept but ignored.
12. Settings tab: `/settings/expiry` is the first item in `SETTINGS_NAV`. Its dated sentence follows the fields
    ("A customer who last earned or spent points on 13 Oct 2026 will lose their balance on 13 Oct 2027 unless they're
    active before then."). "View them" opens `/customers?expiring_soon=90`, and the count is that list's total.
13. **Tests:** `tests/integration/LotWriterTest.php` (integration suite, accepted by the user 2026-10-13) covers
    criteria 2–5 and 8 (the expire row and the lot statuses), including the deficit payoff, after a mixed earn / refund_earn /
    negative adjust / restore_refund sequence. The installer test moves from `tests/integration/Pro/InstallerProTablesTest.php`
    to `tests/integration/InstallerLotTablesTest.php` and keeps only the `install()` + `verify()` check.
14. End-to-end verification on the local Pro site: set Expiry to on, inactivity, 1 day. Give a test customer 500 points with
    `wp eval`, then set their `last_activity_at` and `expires_at` back 2 days with `wp db query`. Run
    `wp action-scheduler run --hooks=yayloyalty_expire_points --force`. The customer's ledger shows one `expire −500` row, the
    balance is 0, every lot of theirs reads `expired`, and WooCommerce → Status → Logs has no YayLoyalty error.
    Then `./scripts/run.sh release lite --no-prompt` passes `scripts/validate-build.php`.

## Constraints

- PHP 7.4 syntax in product code (hard rule 4).
- Lite names Pro only at the guarded boot call (hard rule 2). The Pro behaviour here (batch model, final reminder, floor) attaches
  through filters added in `YayLoyaltyPro::boot()`. SPA Pro code goes in `apps/admin/src/pro/`, exported through `pro/index.ts`
  and rendered behind `IS_PRO_BUILD`.
- Only `LedgerService` writes ledger rows and balances (hard rule 5). `LotWriter` is constructed and called only by `LedgerService`.
- Pro data survives Lite (`docs/conventions.md`): every expiry key, Pro ones included, sits in `SettingsHelper` defaults and `schema()`.
  Localized keys never contain the word `pro`.
- All datetimes UTC through `Utils\Dates`. Points are integers.
- Month arithmetic clamps to month end, matching MySQL `DATE_ADD(…, INTERVAL n MONTH)`. PHP and SQL must give the same date.
- Nothing slow or external runs under the customer lock. Emails fire after commit.

## Approach

**Chosen:** Lots in core, written synchronously by `LotWriter` inside `LedgerService::append()`. Both expiry models run on that one
mechanism and differ only in the dates they assign.

**Architecture:** Every positive ledger row opens a lot. Every negative row consumes open lots oldest first. A
positive row that arrives while the balance is negative first pays the deficit, recorded against itself.
After each row, `LotWriter` recomputes `customers.expires_at`. Under the inactivity model that is
`last_activity_at + inactivity_days`, under the batch model the earliest open lot's expiry, and NULL when expiry is off or the
balance is ≤ 0. A daily job reads `customers.expires_at` to find who is due, writes `expire` rows through
`LedgerService::expire()`, and sends reminders. The model is a core setting, but only Pro's filter can make it `batches`.

**Components:**
- `includes/Database/Schema/LotsTable.php`, `LotAllocationsTable.php` hold the DDL (moved from `includes/Pro/…`, namespace
  `YayLoyalty\Database\Schema`), listed in `Installer::schemas()`.
- `includes/Database/Installer.php`: revert `OPTION_DB_TABLES`, `registered_slugs()`, the missing-slug check in
  `maybe_upgrade()` and its in-lock re-check.
- `includes/Repositories/LotRepository.php` covers open lots in FIFO order (`FOR UPDATE` is unnecessary because the customer
  lock serialises them), inserts lots and allocations, updates `remaining`/`status`, sums due remaining, MIN open `expires_at`,
  and the set-wide recompute queries.
- `includes/Services/LotWriter.php`: `record( LedgerEntry $row, Customer $locked_before ): void` handles the lot or allocations
  and then the `customers.expires_at` sync.
- `includes/Services/ExpiryRules.php` is the one source for the rules. `enabled()`, `model()` returns
  `apply_filters( 'yayloyalty_expiry_model', 'inactivity' )`, plus `lot_expires_at( string $created_at ): ?string` and
  `customer_expires_at( Customer ): ?string`, and `Dates::add_months()` / `Dates::add_days()`.
- `includes/Services/LedgerService.php`: `append()` calls `LotWriter::record()` after `apply_aggregates()`. A new
  `expire( int $user_id, int $points, string $due_at ): Outcome` writes an `EXPIRE` row with source `EXPIRY` through `write_keyed()`.
  Its key is `IdempotencyKey::compose( EXPIRE, $user_id, ReferenceType::USER, $user_id, gmdate( 'YmdHis', strtotime( $due_at ) ) )`.
  The docblock gains: "lots are written only here, through LotWriter".
- `includes/Repositories/CustomerRepository.php`: `set_expires_at( int $user_id, ?string $expires_at ): void` (can write NULL),
  `due_for_expiry( string $now, int $limit ): int[]` and `due_for_reminder( string $now, int $max_days, int $limit ): Customer[]`.
- `includes/Engine/Expiry/ExpiryJob.php`: `register()` schedules the recurring action `yayloyalty_expire_points` (daily, group
  `yayloyalty`) on `init` if it isn't already scheduled. Each run expires up to 200 due customers, then sends reminders to up to
  200. When a page is full, it enqueues one more async `yayloyalty_expire_points` run.
- `includes/Engine/Expiry/ExpiryRecompute.php` runs after a settings save that changed an `expiry` key. It does set-based
  `UPDATE`s, first on open lots' `expires_at` and then on customers' `expires_at`, with the same rules as `ExpiryRules`.
- `includes/Engine/Emails/Emails.php` adds `POINTS_EXPIRING_SOON_ACTION = 'yayloyalty_points_expiring_soon_email'`
  (arg: user id) and `POINTS_EXPIRED_ACTION = 'yayloyalty_points_expired_email'` (arg: ledger row id, relayed from
  `yayloyalty_ledger_recorded` for `EXPIRE` rows) and wires the two Lite emails.
- Pro, in `includes/Pro/YayLoyaltyPro.php`:
  - `yayloyalty_expiry_model` returns the stored `expiry.model`.
  - `yayloyalty_expiry_reminder` (args: `$stage`, `Customer`, `$days_left`, `$expiring_points`) returns `'final'` within
    `final_reminder_days`, or `''` below `reminder_min_points`.
  - The final email listens on `yayloyalty_points_expiring_final_email`.
- SPA:
  - `apps/admin/src/pages/settings/ExpirySettingsPage.tsx` + `expiry-settings.form.ts`, with the route in `router.tsx` and the
    first entry in `SETTINGS_NAV`.
  - `apps/admin/src/pro/settings/ExpiryModelSection.tsx` holds the model cards, months, worked example from the store's own
    numbers, second reminder and floor.
  - `settings.schema.ts` gains the `expiry` group.
  - The Emails tab link points at `/settings/expiry`.

**Data and storage:**
- Tables: `yayloyalty_lots` and `yayloyalty_lot_allocations` keep their columns and indexes unchanged. Opening lots
  (`ledger_id` NULL) stay possible in the schema, but nothing creates them. A deficit-payoff allocation has
  `ledger_id` = the positive row that paid. Lot `status` becomes `expired` when an `EXPIRE` row empties it, and `consumed` for any other emptying.
- Settings, a new `expiry` group in `yayloyalty_settings`:

  | Key | Type | Default | Build that uses it |
  |---|---|---|---|
  | `enabled` | bool | `false` | both |
  | `inactivity_days` | int 1–3650 | `365` | both |
  | `reminder_days` | int 0–365 (0 = off) | `30` | both |
  | `model` | `inactivity`\|`batches` | `inactivity` | Pro (Lite keeps it) |
  | `batch_months` | int 1–120 | `12` | Pro |
  | `final_reminder_days` | int 0–365 (0 = off) | `7` | Pro |
  | `reminder_min_points` | limit (null = none) | `null` | Pro |

- `customers.expires_at` and `customers.reminder_sent_at` gain writers. No new columns.
- Uninstall: none (unchanged).

**Integration points:**
- New filters: `yayloyalty_expiry_model` and `yayloyalty_expiry_reminder`.
- New actions: `yayloyalty_expire_points` (Action Scheduler), `yayloyalty_points_expiring_soon_email`,
  `yayloyalty_points_expiring_final_email` and `yayloyalty_points_expired_email`.
- The REST settings endpoint (`SettingsRestController`) validates the `expiry` group and the one-way rule.
- The existing `yayloyalty_ledger_recorded` action now also fires for `EXPIRE` rows.
- YayMail integration: the three emails are existing `LoyaltyEmail` subclasses, so no change.

**UI:**
- Settings → Expiry follows F11, built in the React SPA's existing form pattern (react-hook-form + zod, see `general-settings.form.ts`).
- Free: an "Expire points after inactivity" toggle, "Expire after [n] days", "Remind before expiry [n] days" with a link to Emails,
  the dated sentence, "N customers have points expiring in the next 90 days · View them", and Save. Lite also shows the static
  "What Pro adds to expiry" card, without the spend-order line.
- Pro replaces the toggle row with:
  - the two model cards (with "One-way migration" copy, and the inactivity card disabled once batches is saved);
  - "Points expire [n] months after the day they were earned";
  - the worked example;
  - first and second reminder, and "Minimum balance to remind".
- Saving `model = batches` asks for confirmation first (a `Dialog` from the one-dialog primitive).

**Edge cases and failure modes:**
- Reminder rule with one column: a stage with window `d` days is due when `expires_at − now ≤ d` and
  (`reminder_sent_at` IS NULL or `reminder_sent_at < expires_at − d`). The job checks the narrowest window (final) first,
  sends at most one email per customer per run, and stamps `reminder_sent_at = now` after the email is queued.
  Under the batch model, a new earliest date (after expiry) makes the old stamp older than the new window, so the next reminder fires.
- Expiry off: lots are still written, all `expires_at` are NULL, the job finds nobody due, and no emails go out.
- Balance ≤ 0: `customers.expires_at` is NULL, and the job and reminders skip the customer.
- The customer is active between a reminder and the due date: under inactivity, `expires_at` moves and `reminder_sent_at` clears
  (existing behaviour), so the old due date's key is never used.
- The job overlaps a ledger write: `expire()` takes the customer lock and recomputes the amount under it, and 0 skips. A
  repeat for the same due date is `duplicate`.
- A month-end date (31 Jan + 1 month) clamps to 28/29 Feb in both PHP and SQL.
- Pro→Lite with `model = batches`: Lite's `ExpiryRules::model()` returns `inactivity`, and the next save or recompute moves
  everyone to inactivity dates. Lots keep their rows. Lite→Pro restores `batches` from the stored setting on the next recompute.
- A settings save during a ledger write: the recompute runs without customer locks. A row committed mid-recompute already
  used the new rules, because the option is saved before the recompute starts.
- WooCommerce inactive: the plugin doesn't boot, so nothing is scheduled.
- A large store: the recompute is two set-based `UPDATE`s, and the job pages by 200.

**Decision record:** the implementing PR deletes `docs/decisions/2026-10-09-pro-tables-via-schema-filter.md` (never
accepted or merged) and adds `docs/decisions/2026-10-13-lots-in-core.md`. Both builds keep lots, and `LedgerService` writes them through
`LotWriter` in the ledger row's transaction. `docs/architecture.md` § Data and upgrades and § writers are updated to match.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Pro-only lot tables installed through `yayloyalty_table_schemas` | The design ships expiry in Free too. It needs a seam into `LedgerService` and slug tracking in the installer |
| A typed observer through a filter, or an in-transaction action, as the lot seam | Unnecessary once lots are core. An action would let any plugin run under the customer lock |
| Write lots only while expiry is on | Off→on leaves balances without lots and needs a rebuild |
| Backfill by walking the ledger backwards / one opening lot | No released install holds pre-lot balances (unreleased plugin) |
| Spend order setting (oldest / newest first) | User removed it. Oldest first expires fewer points and keeps FIFO fixed |
| End-of-month expiry dates | User chose the exact day. It matches the field label and needs no time-zone month-end logic |
| A floor at migration time (no lot due before switch + reminder days) | Unreleased, so no old data can expire at once |
| Payoff allocation naming the oldest unsettled negative row | User chose the paying row itself: simpler, with no shortfall search |
| A second reminder column | `reminder_sent_at` compared to each window's start serves both reminders |

## Rollout

Ships before the first public release, so no existing store needs a migration. Expiry defaults to off. Local and staging sites
reset their loyalty data after updating: on any balance that predates lots, the invariant in criterion 5 would fail.

## Open questions

None.
