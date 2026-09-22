# YayLoyalty — Project Changelog

Detailed record of significant changes, features, and fixes. See `project-roadmap.md` for phase/milestone status and `data-model.md` / `codebase-summary.md` for the reference docs these entries link back to.

---

## Unreleased

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

### Changed — Customer drawer: one definition per fact, one implementation per pattern (2026-09-21)

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
reference-doc detail, and `plans/260918-0845-customer-drawer-wave-b/` for the full plan and
phase-by-phase implementation notes, including every phase's live-verification results and code
review findings.

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
fixture location) for the reference-doc detail, and `plans/260917-1504-customer-drawer-wave-a/`
for the full plan and phase-by-phase implementation notes.
