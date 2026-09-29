# YayLoyalty — Project Changelog

Detailed record of significant changes, features, and fixes. See `project-roadmap.md` for phase/milestone status and `data-model.md` / `codebase-summary.md` for the reference docs these entries link back to.

---

## Unreleased

### Added — Earning listeners connected to their WordPress/WooCommerce events (2026-09-28)

- `EarningService::build()` is now the real composition root (campaigns, customers, `PointsEngine`,
  `LedgerService`, `PendingPoints`, gates — `ProgramGate` first, then a validated
  `yayloyalty_earning_gates` filter result), called from `YayLoyalty::__construct()` after Pro boots.
- `OrderListener` attaches `woocommerce_order_status_changed` and `woocommerce_new_order`, both routed
  through one status table (award set first, then stage/cancel/refund-zero/no-op cases).
  `ReviewListener` attaches `comment_post` and `transition_comment_status`, filtered to approved
  product reviews. `SignupListener` attaches `user_register`.
- Handler bodies (`on_stageable`, `on_awardable`, `on_cancelled`, `on_refunded`, `on_approved`,
  `on_unapproved`, `on_registered`) are still no-op stubs — **no points are awarded yet**. Each routing
  entry point wraps its call to one of them in a removable `try`/`catch` scaffold.
- `general.award_statuses` setting (default `processing` + `completed`, an emptied list reads back as
  the default) owned by `SettingsHelper::award_statuses()`. Pro's purchase-history conditions now count
  the same statuses instead of a hardcoded `processing` + `completed` list. `SettingsHelper::get_settings()`
  merges per field, so a stored list replaces the default list instead of being spliced into it.
- **Tests:** +5 unit tests (`ListenerRegistrationTest`) covering hook attachment and the DI graph; +2 in
  `SettingsHelperTest` for the award-set list merge and fallback.
### Added & Changed — Campaign CRUD end to end: create, update, archive, delete, performance analytics (2026-09-25)

- **Campaign create:** `/campaigns/new` shows a template chooser (`CampaignTemplatePicker.tsx`, three built-in templates: purchase/signup/review); POST `/campaigns` persists to real database. Lite shows read-only built-in cards; Pro shows the picker and form.
- **Campaign update:** `/campaigns/:id/edit` (Pro only, Lite 404) — six-section form (Basics, Trigger, Reward, Conditions, Schedule, Priority) + live summary rail. PUT `/campaigns/{id}` persists for real: `CampaignRestController::update_item()` validates config/conditions/dates, applies updates, calls `$campaign->to_update_row()` and writes via `CampaignRepository::update()`. Built-in campaigns refuse archive but allow pause/name edit. Lite's three built-in cards have an On/Off toggle + Save changes button (status + config writes only).
- **Campaign archive/pause:** Dialogs (`pro/campaigns/archive/`, `pro/campaigns/pause/`) opened from table row menu and bulk bar; copy/figures (points_issued_lifetime) live in `*.copy.ts` files. Built-in campaigns cannot be archived (403 from server). `points_issued` is a ledger-derived read field; fixture value was removed and `CampaignPerformanceFixture` (574 lines) is gone.
- **Campaign performance analytics:** `/campaigns/:id/performance` (Pro only, purchase/signup/review triggers) — **real data from `Pro/Repositories/CampaignPerformanceRepository`** (ledger aggregates + paged/searchable entries), not fixtures. Tiles (points issued, redeemed, unique campaigners, avg order value), daily chart (last 30 days), skipped/estimate side panel (both still `null` — need earning engine F01-4), searchable/paginated entries table. `skipped`, `estimate`, `avg_order_subtotal` remain `null` pending the award path.
- **Lookup route:** `LookupRestController` — `GET /lookups/{entity}` (products, categories, customers) with `search` or `ids` params, returns paginated `{ items: [{ id, label }] }`. Used by condition value pickers in campaign/reward editors.
- **Validation & guards:** Rate guards refuse a purchase campaign with rate ≤ 0 or missing (in rate mode), or multiplier on the default purchase campaign (`CampaignRateGuardTest`). Name/date validation (blank names, end before start, dates that are not full UTC datetimes); a write that leaves the dates alone still lands on a legacy row stored out of order. Archive guard refuses to archive built-in campaigns.
- **Status badge colors:** `CampaignStatusBadge.tsx` + `campaignStatusBadgeVariant()` in `campaign-labels.ts` own one badge variant per display status (active success, scheduled primary, paused warning, draft outline, archived/ended dimmed).
- **Loading skeletons:** Campaigns list (`CampaignPointsSummarySkeleton`) and editor (`CampaignEditorSkeleton.tsx`) show skeletons shaped like the loaded page.
- **Utilities:** `Utils\Text::plain()` removes HTML tags and entities from store names; `Utils\WooOrders::is_hpos()` centralizes the one HPOS check for order admin links.
- **Tests:** Integration suite now 74 tests: campaign archive guard and name/date validation, rate guards (`CampaignRateGuardTest`), lookups by search and id (`LookupRouteTest`), performance repository aggregates/paging/search and deleted-customer snapshot names (`CampaignPerformanceRepositoryTest`). Unit tests for campaign reward copy, status badge colors, stored-date formatting.

### Added — Email Settings page: loyalty transactional emails as WooCommerce email classes (2026-09-24)

- **Email registration:** Each loyalty email is a `WC_Email` subclass registered via WooCommerce's
  `woocommerce_email_classes` and `woocommerce_email_groups` filters. Lite registers 3 (points earned,
  expiring, expired); Pro adds 9 more (birthday, tier changes, referral, rewards, coupon issued). Email
  id is the registration key and WooCommerce settings option name (`woocommerce_{id}_settings`).
- **Admin page** (`/settings/emails`, `EmailsSettingsPage.tsx`): per-email toggles grouped by category
  (Points, Rewards, Referral, Tiers, Promo). Free shows 3 editable; Pro shows 12. Weekly per-customer
  send cap (`throttle_per_week`, not enforced yet) and read-only "Sending" card with WooCommerce's
  live from-name/from-address.
- **Server:** `EmailsRestController` (GET/POST `/emails`) lists registered emails and manages toggles
  (WooCommerce options via `EmailsHelper::update_emails()`) and throttle (plugin settings patch).
  `EmailsHelper::registered_emails()` lists what this build registered; no trigger() hooked yet.
- **Storage:** On/off lives in `woocommerce_{id}_settings` (WooCommerce's standard per-email option);
  throttle in `yayloyalty_settings.emails.throttle_per_week`. Old `yayloyalty_emails` option (dev
  data) orphaned and not migrated.
- **Frontend:** Form state covers email toggles + `throttle_per_week`; read-only metadata/sending never
  posted. Editability gated by `IS_PRO_BUILD`.
- **Tests:** Integration suite (+20 tests, 48 total) covers email registration, REST routes, and
  settings management (reads/writes WC and plugin settings, restoring both after tests).

### Changed — Plugin settings are now patched, not replaced (2026-09-24)

- `SettingsHelper::update_settings()` is now a field-level patch: writes only the fields sent in the
  request, preserving unchanged fields and never storing defaults. POST `/settings` (via
  `SettingsRestController`) is therefore idempotent and safe for partial updates (e.g., changing only
  `emails.throttle_per_week` without affecting other settings). Mirrors POST `/design` behavior.

### Fixed — Balance-only adjustments are rebuildable from the ledger again (2026-09-24)

- New `LedgerType::CORRECTION`: a manual adjustment with "Adjust lifetime totals too" off now writes a
  `correction` row instead of an ordinary `adjust` row. `LedgerService::adjust()` picks the type up front;
  `apply_aggregates()` gates purely on `LedgerType::affects_lifetime_earned()`, so the `$adjust_lifetime`
  flag is no longer threaded through `record()`/`apply_aggregates()` — the row's own type decides.
  `customers.lifetime_earned = SUM(earn) + SUM(adjust) − SUM(|refund_earn|)` is fully rebuildable from
  the ledger again. `DashboardRepository` and Pro `CampaignPointsRepository` drop their private
  `earn_types()` copies for `LedgerType::lifetime_earned_types()`, so points-issued excludes `correction`;
  `LedgerType::is_manual_adjustment()` (adjust + correction) gates the activity log and `note` exposure.
- Tests: `LedgerTypeTest`, `LedgerEntryTest`, a `correction` case in `CustomerActivityServiceTest`;
  `LedgerGuardTest` asserts the written type. Unit suite 254 → 264 tests (62 → 61 incomplete).
- SPA: `correction` added to the ledger type enum, grouped into the drawer's existing "Adjust" filter
  bucket (`LedgerQueryArgs::group_of()`), described as "Balance correction" (or the admin's note) by
  `describeLedgerEntry()`.
- Pre-existing balance-only `adjust` rows (written before this change) stay indistinguishable from real
  adjustments — accepted, dev data only, no backfill.

### Added — Source-of-truth check (2026-09-24)

- **Standards:** `code-standards.md` § 16 names one owner per rule (option names, settings,
  design bounds, points math, enums, conditions, capabilities, wire shapes, fixtures) and the
  mirrors the PHP/TS boundary forces; § 17 adds four body-level readability rules for new code.
- **Skill:** `/source-of-truth-check` (`.claude/skills/`, now tracked) reports code in a diff,
  a branch (`--branch`, against `dev/edd`) or the whole repo (`--all`) that re-declares a rule
  the table assigns elsewhere, and fixes only on approval. `evals/cases.md` holds 8 seeded cases.

### Added — Design settings tab (2026-09-24)

- **Admin** (`/settings/design`, `pages/settings/Design*`, Pro sections in `pro/design/`): launcher,
  storefront text and visibility (Free); panel, typography and tier badges (Pro, mounted through
  `@/pro`). "Only the pages I choose" picks published pages (`visibility.include_page_ids`) with a
  searchable picker that lists the first 20 A-Z; hide-for-roles offers real roles from the new
  `wpMeta.roles` bootstrap key. Admin-side only: nothing renders on the storefront yet.
- **Storage:** own option `yayloyalty_design` via `DesignHelper`; each field's rule (type plus
  limits) is declared in `DesignSchema::schema()` and applied by `DesignCoercion`. Lists replace
  their default whole. A blank launcher label, balance heading or redeem button is refused.
- **REST:** `GET/POST /design`, `POST /design/reset` (one group, or all when no group is sent).
- **Build:** `validate-build.php` fails when a Pro-only design field appears in its section file
  without an `IS_PRO_BUILD` guard. Fixture `apps/admin/tests/fixtures/design-defaults.json` is
  regenerated by `php scripts/dump-design-defaults.php`.

### Fixed — Booleans and form resets (2026-09-24)

- `DesignCoercion` and `SettingsHelper` read booleans with `rest_sanitize_boolean()`, so a posted
  `"false"` or `"0"` is stored as false instead of true.
- `form.reset()` merges the form's `resetOptions`, so `keepDirtyValues: true` also kept edited
  fields after a save. The campaign editor save and the Design save and reset-all now pass
  `keepDirtyValues: false`.

### Added — Campaign archive, pause and bulk actions (2026-09-24)

- **Dialogs** (`pro/campaigns/archive/`, `pro/campaigns/pause/`): single and bulk archive, and pause,
  opened from `CampaignTable`'s row menu and bulk bar. Copy lives in pure `*.copy.ts` files; figures
  come from the resource's new readonly **`points_issued_lifetime`** (fixture value set through
  `CampaignRestController::with_lifetime()`, the one swap point for the real ledger sum). Archived
  rows are hidden unless filtered for by name; built-ins can't be selected for archive.
- **Server:** `update_item()` answers 403 when a built-in is set to `archived` (pausing stays
  allowed); `delete_item()` now resolves the fixture row and does not write, like update — fixture
  ids 1-3 collide with real built-in ids. The fixture grows to 23 rows (an ended row for the
  archive dialog's finished case). Integration test: `CampaignArchiveBuiltinTest`.
- **Shared helpers:** `settleInChunks()` (`lib/helpers/settle-in-chunks.helper.ts`, 10 in flight)
  replaces the copied chunked `allSettled` loops in the Customers and campaigns bulk writes;
  `BulkActionsMenu`, `findBuiltinCampaign()`, `toastApiError()` (stays quiet only for codes
  `afterResponse` already toasted, so a plain 403 now shows) and `pointsPhrase()` (moved to
  `points.helper.ts`) replace per-screen copies. `updateCampaign()` takes a partial body.
- **Docs:** removed a duplicated `CampaignPerformanceRestController` note in `codebase-summary.md`.

### Changed — Docs split per table, one owner per fact (2026-09-23)

- `docs/data-model.md` (111 KB) is now a 17 KB index — conventions, relationships, invariants, write
  paths — with per-table specs in `docs/data-model/` (`campaigns`, `rewards`, `customers-and-ledger`,
  `redemptions`, `future-tables`). Content moved verbatim; code comments citing a table now name its
  file.
- Repeated facts have one owner, listed in `codebase-summary.md` → "Where each fact lives": test
  suites and counts in `code-standards.md` § 14, Pro seams in `system-architecture.md`, dev fixtures
  in `deployment-guide.md`, route id rules in `code-standards.md` § 9. Corrected while merging:
  `yayloyalty_max_manual_adjustment` is a global sanity ceiling, `adjustment_ceiling` is site-wide
  (non-administrators), the dashboard fixture gate is arg registration plus a handler re-check (no
  `requested()` helper), and database-backed tests belong in the integration suite.
- The two point-in-time scout reports moved to `docs/archive/`.
- **`scripts/check-docs.sh`** guards it: doc paths cited in code exist, relative links resolve, docs
  stay under 800 lines (the data-model index under 250), test counts appear only in § 14.

### Added — Adjust points dialog: single and bulk targets, stale-balance check, lifetime option (2026-09-23)

- **Dialog** (`CustomersAdjustPointsDialog.tsx`, `components/adjust-points/`): one dialog with an
  explicit single/bulk target, opened from the Customers bulk bar and the drawer. Single reads the
  customer from `GET /customers/{id}` (the drawer's own query, one fresh read per open); bulk reads
  **`POST /customers/adjust/preview`** (`CustomerAdjustPreviewRestController`, `manage_points`):
  a filter's `count` (null for a ticked list), store-wide `liability_points` and
  `points_per_currency_unit`. `toServerFilters()` owns the tier → `balance_range` substitution for
  the list, the bulk walk and the preview; `MAX_MATCHING_ROWS` owns the 50,000-row bulk cap.
- **Guards:** a typo guard asks for the amount twice when it exceeds both 10 currency units' worth
  of points and `TYPO_GUARD_MULTIPLIER` (3) × the customer's lifetime earned; a pre-submit re-check compares the balance (and user id) to the baseline captured while open and
  fails closed on a failed refetch or missing baseline; the dialog refuses to close while the
  check or save runs. A partial bulk failure narrows the selection to the failed customers and
  resets consent; a "selection changed" abort shows the count the submit resolved.
- **Adjust lifetime totals too** (`adjust_lifetime` on `POST /customers/{id}/adjust`): on, a grant
  or a deduction moves `lifetime_earned` by the same signed amount as the balance; off moves the
  balance only. Integration suite 26 → 27 tests (`LedgerGuardTest`). The ledger row now records the
  choice as its own type (`LedgerType::CORRECTION`) — see "Fixed — Balance-only adjustments are
  rebuildable from the ledger again" below.
- **UI only, not wired:** "Notify the customer by email" and "Also show a description to the
  customer" — both listed as not started in the roadmap.
- **Tests:** `apps/admin/tests/customers-adjust-points.form-state.test.ts` pins the form-state
  rules (liability contribution/delta below zero, bulk effective count, `canSubmit()`).

### Fixed — Points convert to money at the store currency's decimals, one way everywhere (2026-09-23)

- `PointsMath::points_to_currency()` / `pointsToCurrency()` take the currency's decimals
  (`wc_get_price_decimals()`) instead of a fixed 2: a 3-decimal currency (KWD) kept only 2, and a
  0-decimal one (VND) rounded twice. `DashboardStats` and `Clamp::apply()` now receive the
  decimals from their caller; vectors cover 0, 2 and 3 decimals.
- The customer drawer and adjust dialog used their own `points / rate` label, which let
  `toFixed()` round the binary float: 1,005 points at 1,000/unit showed $1.00 where the server
  and dashboard say $1.01. One `formatPointsAsMoney()` now serves the drawer, the dialog and the
  dashboard (replacing `pointsToMoney()` and `pointsAsCurrency()`).
- `parseWPCurrency()` applied the store's decimal separator only at exactly 2 decimals
  (1234.567 in a comma-decimal, 3-decimal store showed `1.234.567` instead of `1.234,567`); it
  now splits before grouping. The unused `parseWPDecimal()` is removed.

### Changed — Customer routes name which user ids they accept (2026-09-23)

- `AbstractRestController::user_id_param( ?CustomerRepository )` used its argument as a hidden
  switch: passing a repository also accepted a deleted member. Split into `member_id_param()`
  (live WP user, or a surviving customers row) for the five drawer reads, and `user_id_param()`
  (live WP user only) for adjust and Pro's campaign preview. `RedemptionsRestController` and
  `ReferralsRestController` lose their constructors; `LedgerRestController` and
  `ActivityRestController` take only their own repository. No route behaviour changed.
- **Tests:** `tests/integration/Controllers/CustomerIdRouteParamTest.php` (4 tests, 16 route
  cases) pins it: drawer reads answer 200 for a live and a deleted member and 400 for an unknown
  id; adjust refuses a deleted member with `id` as the only invalid arg and writes nothing.
  Integration suite 10 → 26 tests; its bootstrap raises `memory_limit` to 512M because booting
  the full REST server with WooCommerce's routes outgrows the CLI's 128M.
- `code-standards.md` § 9, `system-architecture.md`, `deployment-guide.md` and
  `codebase-summary.md` stop describing the removed customer-drawer `?scenario=` fixtures.

### Added — The ledger's duplicate-key guard is real (2026-09-23)

- **`QueryException`** captures the driver's error number at construction (the next statement
  replaces it) and `is_duplicate_key()` compares it to 1062 — a number from the handle, never a
  substring of the message. `get_errno()` exposes it for logging.
- **`LedgerRepository::insert_unless_duplicate()`** returns null instead of throwing when the
  UNIQUE `idempotency_key` rejects the row, with wpdb's error reporting suppressed for the
  attempt: the guard firing is the system working and must not log like a failure.
  `find_by_idempotency_key()` implemented — the pre-check before the insert, and the row
  `Outcome::duplicate` carries after a collision.
- **`LedgerService::earn()`** implemented: assert EARN + positive points + key, then one
  transaction — `ensure_row`, `lock_and_get`, pre-check under the lock, guarded insert,
  aggregates, queued event. A duplicate moves no aggregate and queues no event.
  `Outcome::awarded()`'s evaluation is now optional, since the writer never sees the Result.
- **Verified against MySQL 8** (scratch table, dropped): errno 1062 readable through `wpdb->dbh`
  after a suppressed insert, one row survives, NULL keys do not collide — so manual `adjust()`
  rows stay non-idempotent as designed.
- **Known gap:** the column and index reach a site only through `dbDelta`, which
  `Installer::maybe_upgrade()` skips while `yayloyalty_db_version` equals `YAYLOYALTY_VERSION`.
  Sites installed before the DDL changed keep the old ledger and have no guard.
- **Tests:** 208 → 214; `tests/unit/Database/QueryExceptionTest.php` (6 real). The award path's
  DB-dependent invariants remain the incomplete specification under `tests/unit/Services/`.

### Added — Integration suite with a database under it (2026-09-23)

- **`tests/integration/`** with its own `phpunit-integration.xml.dist` and `composer test:integration`.
  Boots real WordPress through `wp-load.php` and talks to real MySQL; `YAYLOYALTY_WP_ROOT` and
  `YAYLOYALTY_TEST_SOCKET` point it at a site, both optional on a normal dev checkout.
- **Isolation is by table prefix.** `Utils\Table::name()` composes from `$wpdb->prefix` per call, so
  the bootstrap reassigns it to `ylit_`; plugin tables are created there and dropped at shutdown,
  with the prefix asserted before each `DROP`. No option written, no site table read. The bootstrap
  bails on a missing `wp-load.php`, a missing socket, an inactive plugin or a site already on the
  scratch prefix.
- **`LedgerGuardTest`** (10 tests, 1 incomplete) proves against MySQL: an award writes one row and
  moves the aggregates; a second award for one order and trigger is `Outcome::duplicate` carrying
  the first row; `insert_unless_duplicate()` returns null on the second insert with no pre-check
  involved, which is the index doing the work; a non-1062 failure still throws; a duplicate moves no
  aggregate and does not touch `last_activity_at`; `balance_after` is an unbroken chain; manual
  adjustments write two rows with NULL keys; `yayloyalty_ledger_recorded` fires once, not twice.
- **`LedgerServiceTest` narrowed** from 7 specifications to 4 — the rest are now assertions
  elsewhere. Unit suite 214 → 211, incomplete 64 → 61.
- **Still unproven:** the collision branch inside `earn()` (its pre-check runs under the row lock,
  so one connection can never reach it), lock serialisation, `redeem()`, and the nested-commit gap
  in `dispatch_events()`. Each says so in its own `markTestIncomplete`.

### Added — Customers row created at WP registration (2026-09-23)

- New `user_register` hook (`CustomerActivityListener::on_user_register()`, registered in
  `yayloyalty.php`) calls `CustomerRepository::ensure_row()` for every new WP user, admin-created
  accounts included, except staff (`edit_posts` or `manage_woocommerce` — admins, shop managers,
  editors), who are not loyalty members just for having an account. Fixes a gap where a user created in wp-admin never showed up in the admin
  Customers list until their first points activity — `yayloyalty_customers` row creation is now
  eager on registration instead of lazy-on-first-earn. No signup bonus, no ledger write, no
  `account_created` activity-log entry — that stays lazy, tied to the first real ledger write, as
  before. See `system-architecture.md` → "Signup capture" and `data-model.md` →
  `yayloyalty_customers`.

### Changed — Award triggers on a set of statuses (2026-09-23, prose)

- `OrderListener` rewritten as specification: one hook (`woocommerce_order_status_changed`,
  routed on `$to`, never `get_status()`), award when `$to` is in `general.award_statuses`
  (default `processing` + `completed`), stage on `pending`/`on-hold`, nothing on `checkout-draft`,
  `failed` or `refunded` — the last except when `get_total_refunded()` is 0, an out-of-band
  admin reversal referenced to the order. `on_created` renamed `on_stageable`: staging follows a
  transition now, not order creation. Bodies remain `TODO(F01-4)`.
- Settings shape reserved in `SettingsHelper` prose: `general.award_statuses` and an unread
  `hold_days`. The custom choice list is built from `wc_get_order_statuses()` at render time.
- `Engine/Earning/README.md` invariants 4–7 rewritten; `system-architecture.md` and
  `project-roadmap.md` record the guard and the paid-set rule.

### Changed — Redemption restructured into three zones (2026-09-22, scaffold)

- **Zone 1 quote (`includes/Engine/Rewards/`):** new `Clamp::apply()` — the single, pure, tested place
  every limit is computed (balance → `max_points_per_order` → cart after other coupons /
  `max_percent_of_subtotal`, floored to `increment`, zeroed below `min_points`) and reported as
  `Result::get_bound_by()`. `Result` widened with `points_requested` and `bound_by`; `Context` gains
  `CustomerFacts`; new `ContextFactory` (stub). `RewardEngine` loses its gate TODOs (moved to gates);
  `FlexibleDiscountHandler::quote()` becomes a one-line delegate stub.
- **Zone 2 commit (`includes/Engine/Redemption/`, new, mirrors `Engine/Earning`):** `RedemptionService`
  (`quote` / `checkout_error` / `commit`), `Quoter` (real: gates then engine) + `Gate` + `ProgramGate` / `RewardGate`,
  `SessionIntent` (one integer, `yayloyalty_redeem_points`), `CouponProvider` (virtual
  `yayloyalty-redeem`, `individual_use` false, sorted last), `CommitWriter` (one transaction, locked
  re-quote, hard-refuses below zero), `Adapters/ClassicCheckoutAdapter` + `Adapters/StoreApiAdapter`.
  Zone 3 named as `Engine\Redemption\Reversal`, not designed.
- **Reused, widened:** `LedgerService::redeem()` beside `earn()` over the same `record()`;
  `Earning\IdempotencyKey::compose()` fifth segment is now a qualifier — campaign trigger for earn
  rows, reward type for redeem rows; `Earning\Outcome::redeemed()` and `get_evaluation()` typed
  `?object` (PHP 7.4 has no unions).
- **`Repositories\CustomerReader`** (interface, `find_by_user_id()` only), implemented by `CustomerRepository`;
  `Rewards\ContextFactory` depends on it, so the quote path cannot write by type.
- **New persistence scaffold:** `Models\Redemption`, `Repositories\RedemptionRepository` (stubs),
  `Database\Schema\RedemptionsTable` (DDL stub, not registered).
- **Removed:** `Result::REASON_NOT_IMPLEMENTED` and the test asserting it; the stale `worth`
  comments on `RewardRepository`, `RestAPI` and `CustomerRestController`.
- **Tests:** 152 → 208 cases (64 incomplete); `ClampTest` (14 real), `QuoterTest` (3 real),
  `IdempotencyKeyTest` redeem cases (real), `tests/unit/Redemption/*` specification incl.
  `test_no_database_write_occurs_during_a_quote`.
- `docs/archive/redemption-scout.md` records the pre-restructure inventory.
- **Docs (2026-09-23):** `data-model.md` 0.8 catches up — the redemptions section now states what
  exists in code (schema class, model, stub repository) versus what still creates nothing, documents
  the `uk_order_reward (order_id, reward_id)` double-debit guard, and the ledger's `idempotency_key`
  is described with its qualifier segment and both writers.

### Added — Earning orchestration scaffold (2026-09-22)

- **`includes/Engine/Earning/`:** the evaluate/award split around the pure `PointsEngine`.
  `EarningService::preview()` (gates → engine) and `award()` (preview again at the same
  `occurred_at`, then `LedgerService::earn()` in one transaction), `Gate` + `ProgramGate`
  (`excluded_roles` setting, `_yayloyalty_opted_out` user meta), `PendingPoints`, `Outcome`
  (`awarded` | `skipped` | `duplicate`), `IdempotencyKey`, and `Listeners/{Order,Signup,Review}Listener`.
  Every body is a `TODO(F01-4)` stub except `IdempotencyKey`, `Outcome`, `CustomerFacts` and the
  gate-chain loop; listeners are not registered at boot. Invariants in the package README.
- **Ledger idempotency:** one `idempotency_key VARCHAR(96)` column under UNIQUE
  `uniq_idempotency_key` (`{type}:{user_id}:{reference_type}:{reference_id}:{trigger}`) replaces the
  composite `uniq_ledger_event`; `Installer::run_migrations()` → `drop_index_if_exists()` (stub)
  removes the old index on upgrade. `LedgerEntry::get_idempotency_key()`; null on `adjust`.
- **Engine:** `PointsEngine::evaluate()` drops its `$at` argument and reads `Context::occurred_at`;
  `ContextFactory::from_order()` throws without an order creation date. New `CustomerFacts`
  (read once by `ContextFactory::customer_facts()`) so `ConditionsEvaluator` compares instead of
  querying — interim: it still folds over the user id. `Campaign::conditions_match()` moved into `PointsEngine` (private), keeping the model engine-free.
- **Repositories:** `LedgerRepository::find_by_idempotency_key()` / `points_earned_today()`,
  `CustomerRepository::apply_pending_delta()` (stubs); `find_by_reference()` / `latest_for_user()` removed.
- **Settings:** `excluded_roles` (`slug_list`) added to `SettingsHelper` defaults.
- **Tests:** 115 → 152 cases; the 27 incomplete ones under `tests/unit/Earning/`, `tests/unit/Services/`
  and `ContextFactoryTest.php` are the scaffold's specification. `docs/archive/points-engine-scout.md` records
  the pre-scaffold inventory.

### Fixed — Customer drawer empty-state text overflow

- The Points/Activity tabs' empty-state body text (`DrawerTable.tsx`) inherited `whitespace-nowrap`
  from the base `TableCell` component (meant for data-row cells) and never overrode it, so the
  empty-state sentence rendered on one unbroken line and overflowed past the drawer, forcing a
  horizontal scrollbar. Added `whitespace-normal` to the empty-state cell.

### Added — Customer meta table, real account-deletion capture, real activity log (2026-09-23)

- **New table:** `yayloyalty_customer_meta` — generic per-customer key-value event log, doubling as
  the activity log's backing store (`Models/CustomerMeta.php`, `Repositories/CustomerMetaRepository.php`,
  `Repositories/CustomerMetaActivitySearch.php`, `Database/Schema/CustomerMetaTable.php`). See
  `data-model.md` for the full column/index/envelope reference.
- **Real capture, three of eight `Enums\ActivityEvent` types:** `account_created` and
  `points_adjusted` (`Services\CustomerActivityService`, subscribed to `yayloyalty_ledger_recorded`)
  and `account_deleted` (subscribed to `delete_user`/`wpmu_delete_user` — the first WP-core-hook
  subscriptions in this codebase, `Engine\CustomerActivityListener`). No backfill: a member created
  before this shipped has an activity log starting today.
- **`GET /customers/{id}/activity`** now answers real, filtered, paginated rows instead of an
  always-empty envelope (`ActivityRestController`); a customer whose only history would be one of
  the five unwritten event types still gets an honest empty page.
- **Real deleted-account drawer block:** `deleted_at`, identity fallback, and `loose_ends` are real
  on `GET /customers/{id}` (`CustomerItemFormatter`), gated on the WP user currently being gone
  (not just a deletion row existing, so a reinstated id self-heals). `yayloyalty_customers` and its
  balance are kept forever, byte-identical — that frozen balance IS the liability the drawer shows.
- **Wire contract change:** `loose_ends.void_coupons`/`credited_referrals`/`counted_in_members` are
  now nullable on both PHP and Zod (`customer.schema.ts`) — `null` renders as "Not tracked yet" in
  the SPA, not hidden. `activity.schema.ts` gains `account_deleted`; `account_created`'s sub-line
  copy is now points-only (no campaign name on the real path).
- **Admin Customers list** (`CustomerRepository::paginate_list()`) switched `wp_users` from
  `INNER JOIN` to `LEFT JOIN` so a deleted member's row — and the liability it represents — still
  appears, with `CustomerListFormatter::format_row()` falling back to the deletion snapshot for
  display.
- **Docs corrected:** `project-roadmap.md`'s "GDPR erasure on account deletion ✅ SHIPPED" row and
  `codebase-summary.md`'s `yayloyalty_erase_customer_data` seam both claimed a
  `Engine\UserLifecycle`/`Services\CustomerErasureService` that never existed — both corrected;
  anonymisation/erasure remains not started and must be re-specified against the "row kept forever"
  policy this task locks in.

### Removed — Customer drawer `?scenario=` fixture system (2026-09-23)

The drawer's UI had been fully verified against every fixture scenario, so the WP_DEBUG-gated
dev-only data source is no longer needed — only the real path remains.

- **Deleted:** `includes/Controllers/Fixtures/CustomerDrawer/` (9 PHP classes: `CustomerDrawerFixture`,
  `DrawerProfiles`, `DrawerLedger`, `DrawerLedgerGenerator`, `DrawerActivity`, `DrawerExpiry`,
  `DrawerNegativeBalance`, `DrawerRedemptions`, `DrawerReferrals`) and their unit test.
- **Server:** the five drawer controllers (`CustomerRestController`, `LedgerRestController`,
  `ActivityRestController`, `RedemptionsRestController`, `ReferralsRestController`) no longer
  register a `scenario` arg or branch on it — each now runs only the real path that was already
  there (honest empty envelopes for Activity/Redemptions/Referrals; `null` blocks for
  `deleted_at`/`expiry`/`negative_balance` — none of those tables/columns exist yet, see
  `data-model.md`).
- **SPA:** `useDevScenario()` (`hooks/useDevScenario.ts`) is no longer called anywhere under the
  drawer — it is still used by the Dashboard page, so the hook itself stays. All drawer api/query
  functions dropped their `scenario` parameter.
- **No behavior change** on the real path: every response byte is identical to a pre-change
  request made without `?scenario=`.
- Wire shapes the fixtures documented (activity rows, `negative_balance`, `expiry.batches`,
  redemption/referral rows) remain fully documented in `data-model.md` — nothing was lost.

### Added — Campaign performance page, Pro (2026-09-21, #16)

- **Route:** `/campaigns/:id/performance` for `purchase`/`signup`/`review` campaigns only — tiles,
  daily chart, skipped/estimate side panel, paged + searchable entries table. Reached from
  `CampaignTable`'s row menu and the editor rail, both behind `hasPerformancePage(trigger)`.
- **Endpoint:** `Pro/Controllers/CampaignPerformanceRestController` — `GET /campaigns/{id}/performance`
  and `.../performance/entries`, cap `MANAGE_CAMPAIGNS`, 404 for unknown id or unsupported trigger
  alike. Answers from `CampaignPerformanceFixture` (deterministic per id); SWAP POINT and the swap
  procedure are in `system-architecture.md` → "Campaign performance".
- **Contract rule:** a `null` field means "not applicable to this trigger" or "not computable yet",
  never `0`; the SPA renders `null` as `—`.
- Also: `components/ui/custom/stat-tile.tsx` (tile without a delta line), `CampaignTable` row
  menu, `campaign-labels.ts` extended.

### Added — Campaign editor, Pro (2026-09-21, #13)

- **Route:** `/campaigns/:id/edit` — six sections (Basics, Trigger, Reward, Conditions, Schedule,
  Priority) plus a live summary rail. `react-hook-form` + `zodResolver` (first use in the SPA);
  `useRouteLeaveGuard` blocks navigation with unsaved changes. Lite mounts a 404 at the route.
- **Data layer:** `lib/schema/campaign.schema.ts` gained `campaignUpdateInputSchema`;
  `updateCampaign()` / `useUpdateCampaignMutation()` added.
- **Server:** `CampaignRestController::update_item()` now runs `Campaign::conditions_are_valid()`
  as a real gate, validates the config whitelist and echoes the campaign — **without persisting**.
  The read side still serves `dummy_fixture()`, so a persisted edit would be invisible; the two
  SWAP POINTs are meant to be flipped together. "Add campaign" stays inert for the same reason.
- **Known gaps:** no product/category/customer search endpoint (id entry only); no store-wide
  ledger aggregate for the rail's cost estimate; `updated_by` never set; `include_shipping` is a
  dead config key.

### Changed — Customer drawer: one definition per fact, one implementation per pattern (2026-09-21, #14)

A review-driven cleanup of the drawer, wire-neutral on every endpoint (fixture payloads were
diffed byte-for-byte before/after) and visually unchanged bar one 8px gap noted below.

- **Dead code:** `DrawerFiltersCollapse.tsx` (the mobile "Filters (N)" trigger) had stopped being
  rendered but its file, import, `activeCount` and docblock remained — removed. The Wave B note
  below describing that collapse is now history.
- **Source of truth:** `LedgerQueryArgs::group_of()` owns the raw-type→filter-group mapping;
  `ActivityQueryArgs::EVENT_TYPES`/`ACTOR_KINDS` are the one PHP definition and the activity item
  schema now declares them as `enum`s (it previously omitted `event_type`'s enum, so the declared
  contract was looser than its Zod mirror). `DrawerProfiles` uses keyed rows with
  `profile()`/`identity_of()` instead of positional tuples and five index accessors; the
  negative-balance story and expiry figures are read off their own ledger rows/batches instead of
  being restated "in sync". `GET /customers/{id}`'s no-row default is `new Customer($id)`'s own
  `to_array()`, not a hand-copied literal. The `WP_DEBUG` + `?scenario=` gate lives once server-side
  (`CustomerDrawerFixture::requested()`) and once client-side (`hooks/useDevScenario.ts`, shared
  with the dashboard); request builders no longer re-check `import.meta.env.DEV`. TS:
  `ledgerTypeCountsSchema`'s shape is `satisfies Record<LedgerFilterType, …>` so key drift fails
  `tsc`; `customerSchema` extends `customerListRowSchema`.
- **Dedup:** the four tab tables share `tabs/DrawerTable.tsx` (shell, skeleton, empty state) and a
  `drawerCell()` class helper; `LedgerTab`/`ActivityLogTab` share `useDrawerTabQueryState()`
  (`customer-drawer.tab-query-state.ts`) and `tabs/DrawerTabFilters.tsx`, replacing the two
  per-tab filter components; `DRAWER_PAGE_SIZE` replaces nine `= 10` constants; the five drawer
  fetchers share `DrawerPageParams`/`toDrawerSearchParams()`; `formatStoredDate()` replaces the
  `parseWPDate(utcToDate())` pair. PHP fixtures share `CustomerDrawerFixture::page()`,
  `search_needle()`, `in_date_window()`, `with_ids()`; the three no-table controllers use
  `AbstractRestController::respond_empty_page()`.
- **Tests:** `tests/unit/Controllers/` pins both filter groupings to their enums and the fixture
  paging/precedence/date-window helpers (115 PHPUnit tests now).
- **Known rendered delta:** the ledger's disabled-filter reason line sits in the filter column's
  8px gap rather than flush under the search row. Accessible name added to the activity group
  control.

Plans: `plans/260921-1536-customer-drawer-source-of-truth/`, `plans/260921-1619-customer-drawer-dedup/`.

### Added — F09 Customer Drawer, Wave B

The six artboards Wave A left undrawn, still UI-first on the same `WP_DEBUG`-gated fixture — no
schema, no migration, no real-data work this wave either.

**Shipped:**

- Activity log tab: full ledger-parity paging + search + date range (replacing an earlier
  "Load more" draft) and a grouped filter (All / Admin actions / Tier changes / Expiry / Emails,
  deliberately overlapping — a lens, not a partition). `GET /customers/{id}/activity` is now a
  real, routed endpoint.
- Deleted-account panels (4a): banner, "What can still be done" (Adjust/Reset disabled, Export
  live, Anonymise a disabled button only — no GDPR logic behind it), "Loose ends this leaves"
  (void coupons, credited referrals, whether the member count still includes them).
- Expiring-points panels (4b): warning banner with loss in money and proportion, four stats with
  currency equivalents (`points_per_currency_unit`, restored narrowly after an earlier commit
  removed the whole `worth` object), a Pro FIFO lot-batch table (Free sees a specific explanation
  sentence, not a generic upsell).
- Negative-balance explanation (5a, labelled F23 in code — a separate feature renting drawer
  space): states the cause in place (refund reversed more than was left) rather than leaving an
  unexplained negative number; two actions drawn, both disabled.
- Redemptions table (3e) and Referrals table (3f): both table-only (summary strips and the
  revenue/rewards-you-could-use lines are cut — see below), both real paginated endpoints
  (`GET /customers/{id}/redemptions`, `.../referrals`) answering an honest empty envelope on the
  real path, both Pro-gated tab bodies via the existing `ProGate` — all four tabs stay visible in
  both builds. Referrals adds a referral-link + Copy button + campaign-sentence header above its
  table, and a "Referral settings" link gated to the same capability the real `/referral` route
  itself requires.
- Mobile pass (5c): the header stat grid keeps all five stats below `sm` rather than matching the
  artboard's four (nothing silently dropped, one taller row instead); the ledger's three filter
  controls collapse into a single "Filters (N)" trigger below `sm` via the new
  `DrawerFiltersCollapse.tsx`, search staying outside at full width; every phase 02–06 panel/table
  was audited for `sm:`-breakpoint coverage (six-column tables render every column, stacked, never
  hidden behind an expander).

**Recorded, not built:** 4b's forecast ledger row (the ledger holds only real point movements,
on purpose); 5a's two drawn-but-disabled actions (a manual write-off is one existing `adjust` call
away, once someone decides to wire the button); 3e's summary strip and "Rewards X could use now";
3f's summary strip and "Revenue referred" (needs a WooCommerce order join with first-order-only
attribution logic that has never been priced); real Anonymise logic (GDPR erasure, not implemented,
Anonymise is a disabled button only); the tier ordering/basis bug (code ranks by balance, the
design's citations rank by lifetime earned — three independent design citations now, still not
fixed, waiting on a real tiers table); and every table this wave's endpoints still answer an empty
envelope for: Activity log, Redemptions, Referrals (see `docs/data-model.md`).

See `docs/data-model.md` (the new wire-block/endpoint-simplification notes) and
`docs/codebase-summary.md` (three new REST controllers, the drawer's current file layout) for the
reference-doc detail. Full plan and phase-by-phase implementation notes (including live-verification
results and code review findings) are in the planning phase directories.

### Added — F09 Customer Drawer, Wave A

A full-screen/panel drawer (`#/customers/:userId`) opened from a Customers-list row, built UI-first
against a `WP_DEBUG`-gated PHP fixture (six named scenarios) so every state — new/zero, large
figures, deleted, expiring, negative balance — could be reviewed before the real data path had to
carry all of them. The default (no `?scenario=`) request always serves the real repository, so the
drawer already agrees with the real Customers list; swapping any remaining fixture-only surface to
real data is a controller-only change, never an SPA change (every fixture scenario parses through
the same Zod schema as the real path).

**Shipped:**

- Six-stat header (Balance, Pending, Expires, Lifetime earned, Lifetime redeemed, Worth) on an
  `auto-fit`/`minmax(150px,1fr)` grid; figures are never abbreviated.
- Actions row: Adjust points (live — reuses the existing bulk-adjust dialog/mutation/invalidation);
  Reset expiry / Export ledger / Assign tier (Pro) are visible but permanently disabled in Wave A,
  each with a stated reason — no endpoint yet.
- Four-tab bar with live counts. Points ledger: paged (10/page, never "Load more"), type filter
  with per-type counts, date range, search over description/order number, running balance column.
  Activity log / Redemptions / Referrals render a distinct, named empty state only — their backing
  tables do not exist yet (see `data-model.md`'s new "Activity log (not designed)" section).
- Deleted-account and negative-balance treatments (disabled actions with reasons; a tinted, never
  red, warning marker — matching the existing Customers-list convention).
- `useDebouncedSearchCommit` extracted from the Customers list's own search box into a shared hook
  (`apps/admin/src/hooks/`), now used by both the list and the drawer's ledger search — a
  behavior-preserving refactor, not a rewrite.

**Recorded, not built (Wave B):** real ledger filters and real per-type counts (`type_counts` is
`null` on the real path — deliberately not bundled, they are different costs at scale); Export
ledger as a background job; the `customers.deleted_at` column + soft-delete write path (wire field
exists, no column yet); the Activity log table itself (unpriced — the largest gap this wave
surfaces).

See `docs/data-model.md` (Activity log gap, `deleted_at`), `docs/codebase-summary.md` (drawer route,
fixture location) for the reference-doc detail. Full plan and phase-by-phase implementation notes
are in the planning phase directories.
