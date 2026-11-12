# Phase 3: Customers list, decision record and end to end

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (`TierBadge`, `TIER_QUERIES.list()`, `useTiersEnabled()`, the evaluator) and Phase 2 (the drawer card, for the end-to-end walk).
**Delivers:**
- The Customers list's Tier column, sort and multi-select filter, and saved segments that keep the tier.
- Bulk adjust that honours the tier filter.
- The decision record and the backlog update.
- A validated Lite build.

**Exit criterion:**
- Spec AC 13, 14 and 16 pass by Task 1's and Task 2's checks.
- The spec's end-to-end AC 17 passes on wp-env (Task 3's last step), with no console error.
- `./scripts/run.sh release lite --no-prompt` passes validation.

### Task 1: Lite list filter, sort and row field

**Done when:** `GET /customers?tier=…`, `orderby=tier` and `POST /customers/adjust/preview` with `filters.tier` meet spec AC 13 over REST, and each row carries `tier_id`.

**Files:**
- Modify:
  - `includes/Controllers/CustomerRowFilters.php`: `tier` in `DEFAULTS` (`''`), `from_request()`, `to_criteria()` (parse ids ≥ 1 and `none`, dropping anything else) and `candidates_for_no_match()`;
  - `includes/Controllers/CustomerRowSort.php`: key `tier`;
  - `includes/Controllers/CustomerListFormatter.php`: `filter_params()` gains `tier` (string, `sanitize_text_field`); `respond_list()` passes `TierRepository::live()` ids by rank DESC to `to_order()`; `format_row()` adds `tier_id`;
  - `includes/Repositories/CustomerRepository.php`: `c.tier_id` in the list SELECT; `where_fragments()` gives `(c.tier_id IN (…) OR c.tier_id IS NULL)` in the right combination; `order_by_sql()` gives `(c.tier_id IS NULL) ASC, FIELD(c.tier_id, …) <dir>` with prepared ints, and an empty id list falls back to `c.user_id`;
  - `includes/Controllers/CustomerAdjustPreviewRestController.php`: `filters.tier` (string) in its args.

**Interfaces:**
- Consumes: Phase 1's `TierRepository::live()`.
- Produces: the `tier` param, criteria and sort, and the row `tier_id` (README contract).

Steps: expand after Phase 2 lands. The verification step runs spec AC 13's REST checks, plus a check that an unknown id (`tier=99999`) returns 0 rows rather than an error.

### Task 2: SPA column, filter, sort and segments

**Done when:** spec AC 13 and 14 hold in the Customers UI, and bulk adjust with Tier = Silver counts and adjusts only Silver customers.

**Files:**
- Create: `apps/admin/src/pro/customers/CustomersTierFilter.tsx`, `apps/admin/src/pro/customers/CustomersTierCell.tsx`
- Modify:
  - `apps/admin/src/lib/schema/customer.schema.ts`: `CustomerSortKey` gains `'tier'`, the row gains `tier_id`, and the no-match `key` enum gains `'tier'`;
  - `apps/admin/src/lib/api/customer.api.ts`: `CustomerListParams.tier`, sent when non-empty, and `previewAdjustPoints` `filters.tier`;
  - `apps/admin/src/pages/customers/customers-page.filters.ts`: `tier`, and `initialFiltersFromSearch`;
  - `apps/admin/src/pages/customers/customers-page.filter-suggestions.ts`, `customers-page.sort.ts`, `customers-page.matching-customers.ts`, `customers-page.export.ts`: pass `tier` wherever `balanceRange` is passed;
  - `apps/admin/src/pages/customers/components/CustomersToolbar.tsx`: the `extraFilters?: ReactNode` slot;
  - `apps/admin/src/pages/customers/components/CustomersFilterBar.tsx`: the Pro branch fills that slot;
  - `apps/admin/src/pages/customers/components/CustomersTable.tsx`, `CustomerCardRow.tsx`, `CustomersTableCells.tsx`: an `IS_PRO_BUILD &&` Tier head and cell, after Customer;
  - `apps/admin/src/pages/customers/components/adjust-points/AdjustPointsBulkPanels.tsx`: pass `tier`;
  - `apps/admin/src/pro/customers/customer-segments.api.ts`, `customers-saved-segment-picker.ts`: `tier: string[]` on the wire, joined into the filter string in the app;
  - `includes/Pro/Helpers/CustomerSegmentsHelper.php`: a list-valued `tier` key, sanitised to live tier ids (as strings) plus `none`, `[]` by default;
  - `apps/admin/src/pro/index.ts`.

**Interfaces:**
- Consumes: Task 1's `tier` param and row `tier_id`; Phase 1's `TierBadge`, `TIER_QUERIES.list()` and `useTiersEnabled()`.
- Produces:
  - `CustomersTierFilter({ value: string; onChange: (value: string) => void; disabled: boolean })`: a `MultiSelectCombobox` with the placeholder "Tier", over live tiers plus `{ value: 'none', label: 'No tier' }`;
  - `CustomersTierCell({ tierId }: { tierId: number | null })`: a `TierBadge`, or a muted "—";
  - `CustomerListFilters.tier`.
  - While `! useTiersEnabled()`, neither renders, and the page sends `tier` as `''`.

Steps: expand after Phase 2 lands. Verification covers:
- spec AC 13 (the filter, the sort, and a segment that drops a deleted tier);
- AC 14 (hidden while off);
- bulk adjust with Tier = Silver (the preview count equals the Silver member count, and only Silver ledgers gain the row);
- the Chrome console check (Claude) on `#/customers`.

### Task 3: Decision record, backlog, Lite check and end to end

**Done when:** the decision record exists, the backlog boxes this slice meets are ticked, the Lite release validates, and spec AC 17 passes.

**Files:**
- Create: `docs/decisions/2026-11-04-tier-evaluator-sole-writer.md` (status `accepted`; it records D20 per the spec's Decision record paragraph, in the format of `docs/decisions/2026-10-04-decision-record-practice.md`)
- Modify:
  - `docs/backlog/2026-10-06-tiers-not-built.md`: tick "An evaluator sets…", "The Customers list filters by tier…" and "The drawer shows a customer's tier…", and update `## Today`;
  - `docs/architecture.md` § Server layers and write ownership: one bullet, "`TierEvaluator` (Pro) is the only writer of the customer tier columns; it runs after the ledger commit".

**Interfaces:**
- Consumes: Phases 1–3.
- Produces: nothing.

Steps: expand after Phase 2 lands. The steps are:
1. `./scripts/run.sh release pro --no-prompt`, then `./scripts/run.sh release lite --no-prompt`, then `composer install`, then `git status --short yayloyalty.php readme.txt`. Expected: both validations pass; `git status` prints nothing (`git restore` them otherwise).
2. Spec AC 16 on a Lite build of the wp-env site.
3. The spec's AC 17 walkthrough, steps 1–6, verbatim, with Claude doing the Chrome console check on `#/tiers`, `#/customers` and the drawer.
4. Commit and set the plan Status to `done 2026-MM-DD` only when the user asks.
