# YayLoyalty — Project Changelog

Detailed record of significant changes, features, and fixes. See `project-roadmap.md` for phase/milestone status and `data-model.md` / `codebase-summary.md` for the reference docs these entries link back to.

---

## Unreleased

### Added — Design settings tab (admin UI only, 2026-09-23)

- **New `wp_option`** `yayloyalty_design` (never merged into `yayloyalty_settings`; owns six groups: launcher, storefront_text, visibility, tier, panel, typography) via `DesignHelper`, `DesignSchema`, `DesignCoercion`.
- **Backend:** `DesignRestController` exposes `GET|POST /design` (cap `VIEW`/`MANAGE_SETTINGS`), `POST /design/reset` (optional `group` param, same cap). `LocalizeHelper::get_admin_data()` now carries `design` for the SPA bootstrap (no fetch flash).
- **Frontend:** New SPA route `/settings/design` → `DesignSettingsPage`, registered in both `router.tsx` and `SettingsSideNav.tsx`. Three Free sections (Launcher, Storefront text, Visibility) + three Pro sections (Tier badges, Panel, Typography under `src/pro/design/`, re-exported via `pro/index.ts`, gated behind `<ProGate>{IS_PRO_BUILD ? <X/> : null}</ProGate>`). `design.schema.ts` (zod mirror) + `design.api.ts` + `design.queries.ts` (TanStack Query, staleTime: Infinity + refetchOnWindowFocus). Two reset actions: whole-tab reset (save-action row) and storefront-text-only reset (section header, group-scoped).
- **Build validation:** `scripts/validate-build.php` gains a check that fails if a Pro-only field name appears in a Free-section file without an `IS_PRO_BUILD` guard.
- **Accepted technical debt:** Tier `badges[].tier_id` is a merchant-entered slug, not a foreign key (tiers table not yet built); `typography.custom_css` is stored but rendered nowhere (sanitisation deferred); Pro gating is build-time only, not an authorisation boundary; concurrent saves resolve last-write-wins (narrowed by refetch-on-focus, same gap as `yayloyalty_settings`).
- See `plans/260923-1048-design-settings-tab/plan.md` (5 phases, all complete) and `plans/reports/brainstorm-260923-1048-design-settings-data-structure.md` for schema decisions and red-team findings.

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
- `docs/redemption-scout.md` records the pre-restructure inventory.
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
  and `ContextFactoryTest.php` are the scaffold's specification. `docs/points-engine-scout.md` records
  the pre-scaffold inventory.

### Changed — Docs (2026-09-22)

- GDPR erasure feature corrected to "not started" (no implementation exists yet).
- Build validation: tsc error count fixed to 3 (not 5; per_page/perPage mismatch was already resolved in commit 5a9def2).
- Documented fixture-backed endpoints: WP_DEBUG-gated `?scenario=` dev fixtures vs. unconditional swap-point fixtures that ship in release zips.
- System architecture seams: added `yayloyalty_register_trigger_handlers` and `yayloyalty_localize_admin_data` to filter list.
- Data model indexes: added missing customers table indexes (lifetime_earned, lifetime_redeemed, joined_at).

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
