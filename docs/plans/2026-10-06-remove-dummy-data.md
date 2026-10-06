# Remove Dummy Data Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source.

**Status:** active
**Goal:** Delete every piece of made-up data the plugin ships or generates (the demo customer seeder, the dashboard `?scenario=` fixtures, the Simulate-rebuild toggle, the Pro dashboard dummies, the sample tiers, and the unread test fixtures with their dump script), plus the code that exists only to show it.
**Spec:** none, planned from the request
**Architecture:** Pure removal, one task per kind of dummy data, each leaving the plugin working. A UI that showed dummy values now shows nothing until real data exists. Real API surface stays: the server's `tier` list param (Pro segments use `CustomerFilters::TIER_RANGES`) and the `referrals` / `tier_distribution` fields of `GET /dashboard/pro`.
**Touches:** `includes/Database/`, `includes/Services/LedgerService.php` (shared: its docblock is the construction-site list CLAUDE.md rule 5 points to), `includes/Engine/Redemption/RedemptionService.php`, `includes/Controllers/Dashboard*`, `includes/Pro/Controllers/DashboardProRestController.php`, `includes/Services/Dashboard/NoticeDismissals.php`, `includes/Utils/PointsMath.php`, `apps/admin/src/pages/dashboard/`, `apps/admin/src/pro/dashboard/`, `apps/admin/src/pages/customers/`, `apps/admin/src/lib/{api,queries,schema,points-math}/`, `apps/admin/src/hooks/useDevScenario.ts`, `scripts/dump-design-defaults.php`, fixtures, `README.md`, `docs/architecture.md`, `docs/known-issues.md`
**Tests:** none. The change only deletes code: no points or money math changes, and removing the seeder takes away a `LedgerService` caller without changing `LedgerService`.

## Requirements

- **Goal:** No code path creates, serves or renders made-up data, and no script generates it.
- **Out of scope:**
  - Data already on existing sites: the 5 seeded users, their customer and ledger rows, and the `yayloyalty_demo_customers_initialized` option stay. No upgrade routine deletes them.
  - The server-side `tier` filter (`CustomerListFormatter::filter_params()`, `CustomerRowFilters`, `CustomerRepository`, `CustomerFilters::TIER_RANGES`) and the Pro Referrals column, which renders `—` and shows no made-up value.
  - Lite's `DashboardProTeasers` (PRO badges and `—`, no made-up values).
  - Sample data that previews real settings: `LauncherPreview`'s sample shopper, the email previews, and YayMail's sample award.
  - `apps/admin/tests/fixtures/reward.fixture.ts`: tests read it.
  - `docs/plans/` and `docs/specs/`: kept as history, never edited.
- **Acceptance criteria:**
  1. `git grep -n -E 'InitYayLoyaltyCustomer|yayloyalty_init_demo_customers|DashboardFixture|useDevScenario|scenario|CustomersRebuildBanner|isRebuilding|staleBalances|dashboard-pro-dummy|DUMMY_|tier-sample-data|sampleTier|toServerFilters|TIER_FILTER_ENABLED|TIER_OPTIONS|CustomerDrawerTierCard|mail_failures|balance_rebuild|dump-design-defaults|design-defaults\.json|points-math-vectors' -- ':!docs/plans' ':!docs/specs'` prints nothing.
  2. Re-running `Installer::install()` after deleting `yayloyalty_demo_customers_initialized` creates no users.
  3. `GET /yayloyalty/v1/dashboard?scenario=large` under `WP_DEBUG` returns the site's real figures, not `members.value` 250000.
  4. Pro dashboard: no Referrals tile and no Tier distribution card while the API returns `null` for them. Top campaigns still renders.
  5. Pro Customers page: no Simulate rebuild button, no Tier column, no Tier filter and no drawer tier card. Search, Balance range and Expiring soon still filter the list, the export and Adjust points "all matching".
  6. The type-check prints only the baseline errors, and the end-to-end check in Task 7 passes.
- **Decided with the user:**
  - Which dummy data is in scope? All of it: the demo customer seeder, the dashboard `?scenario=` fixtures, the Simulate-rebuild toggle, and the unread test fixtures with their dump script.
  - What happens to existing seeded data? It stays. Only the code goes.
  - What about the fixture-only notice keys `mail_failures` and `balance_rebuild`? Remove them, with their schemas and copy.
  - What about the Pro dashboard dummies and the sample tiers? Remove both, including the Tier column, the Tier filter and the drawer tier card.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite names Pro in exactly one place, the guarded `YayLoyaltyPro::boot()` call in `includes/YayLoyalty.php`. SPA Pro code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`.
- Only `LedgerService` writes ledger rows and balances, and it is constructed only in the places its docblock lists.
- Never hand-edit `vendor/`, `vendor-prefixed/`, `node_modules/`, `assets/dist/` or `release/`.
- Comments: one-line docblocks, and a why only where the code would look wrong.
- Work on a branch from `dev/edd` named `<name>/chore/remove-dummy-data`. Commits follow Conventional Commits and end with the `Co-Authored-By` trailer. Stage files by name. One PR into `dev/edd`.
- Baselines on `dev/edd`:
  - Type-check: run `node node_modules/typescript/bin/tsc --noEmit` from `apps/admin`, not through pnpm, so nothing auto-installs. The baseline is only the TS2307 errors for `@emoji-mart/data`, `@emoji-mart/react` and `@radix-ui/react-progress`.
  - ESLint: one `no-empty` error in `api.ts`, plus Tailwind warnings. Run `node node_modules/eslint/bin/eslint.js <changed files>` from `apps/admin` and add no new error or warning.
  - Prettier: run `node node_modules/prettier/bin/prettier.cjs --check <changed files>` from `apps/admin` on the files you change.
  - `composer lint`: only warnings in three untouched files. Run `vendor/bin/phpcs <changed PHP files>` and get no output.
  - `composer test` runs no tests and exits 0. Vitest finds no test files and exits 1. Neither is a useful check here.
- wp-cli on the Local site: run from `/Users/shrief/Local-Sites/yaydevs/app/public` as `php -d "mysqli.default_socket=/Users/shrief/Library/Application Support/Local/run/DmdPSy05S/mysql/mysqld.sock" /opt/homebrew/bin/wp eval '…'` (shortened below to `WP eval`).

## Review focus

- **Customers filters after `tier` leaves `RowFilters`:** the list, the export, bulk "all matching" and the Adjust points preview must keep sending exactly `search`, `balance_range` and `expiring_soon`. A dropped spread would silently widen a bulk adjust to every customer. Task 5, Step 3.
- **Dashboard deep link `?balance_range=`:** `initialFiltersFromSearch` must still read it after the Tier options go. Task 5, Step 3.
- **Pro side column reflow:** with the tier card hidden, the skeleton must match what loads: one card, not two. Task 3, Step 2.
- **Unknown notice keys:** after the strip's dismiss guard goes, an unknown key must still never render. `noticeCopy()` returns null for it. Task 2, Step 5.
- **Saved segments with a tier:** a segment saved before this change with a tier other than `all` now loads without it and shows every customer its other filters match. No site had saved segments when this shipped (the `yayloyalty_customer_segments` option was unset on the dev site). Task 5.
- **Lite leak:** none of the edits may import from `@/pro` outside `apps/admin/src/pro/index.ts`. Task 7, Step 2 greps for it.

---

### Task 1: Demo customer seeder

**Files:**
- Delete: `includes/Database/InitYayLoyaltyCustomer.php`
- Modify: `includes/Database/Installer.php:58`: remove the `InitYayLoyaltyCustomer::maybe_create();` line.
- Modify: `includes/Services/LedgerService.php:20-22`: "exactly four places", and drop `Database\InitYayLoyaltyCustomer::maybe_create()` from the list.
- Modify: `includes/Engine/Redemption/RedemptionService.php:64`: "fifth construction site" becomes "fourth construction site".
- Modify: `README.md:47`: "Activation installs the tables." Drop "and seeds demo customers".
- Modify: `docs/known-issues.md:70`: "Activation installs its tables."
- Modify: `docs/architecture.md`:
  - line 63: end the sentence at "writes both".
  - line 89: delete the bullet.
  - line 95: drop the `yayloyalty_demo_customers_initialized` item.

**Interfaces:**
- Consumes: nothing
- Produces: nothing

- [ ] **Step 1: Make the deletions and edits listed above**

- [ ] **Step 2: Verify no reference is left**

Run: `git grep -n -E 'InitYayLoyaltyCustomer|init_demo_customers|demo_customers|demo seeder|seeds demo|five places|fifth construction' -- ':!docs/plans' ':!docs/specs'`
Expected: no output

- [ ] **Step 3: Lint the PHP**

Run: `php -l includes/Database/Installer.php && php -l includes/Services/LedgerService.php && vendor/bin/phpcs includes/Database/Installer.php includes/Services/LedgerService.php includes/Engine/Redemption/RedemptionService.php`
Expected: `No syntax errors detected` twice, and no phpcs output

- [ ] **Step 4: Verify install creates no users**

Run: `WP eval 'wp_set_current_user(1); delete_option("yayloyalty_demo_customers_initialized"); $before = count_users()["total_users"]; \YayLoyalty\Database\Installer::install(); echo $before, " ", count_users()["total_users"], " ", var_export(get_option("yayloyalty_demo_customers_initialized"), true);'`
Expected: two equal numbers, then `false`

- [ ] **Step 5: Commit**

Stage: `includes/Database/InitYayLoyaltyCustomer.php`, `includes/Database/Installer.php`, `includes/Services/LedgerService.php`, `includes/Engine/Redemption/RedemptionService.php`, `README.md`, `docs/known-issues.md`, `docs/architecture.md`
Message: `chore(install): remove the demo customer seeder`

### Task 2: Dashboard `?scenario=` fixtures and fixture-only notices

**Files:**
- Delete: `includes/Controllers/DashboardFixture.php`, `apps/admin/src/hooks/useDevScenario.ts`
- Modify: `includes/Controllers/DashboardRestController.php`:
  - lines 15-17: drop the docblock's `scenario` sentence.
  - lines 103-110: drop the `WP_DEBUG` `scenario` arg.
  - lines 118-120: drop the early return.
- Modify: `includes/Pro/Controllers/DashboardProRestController.php`:
  - line 6: drop the `use`.
  - lines 53-60: drop the `scenario` arg.
  - lines 80-82: drop the early return.
- Modify: `includes/Services/Dashboard/NoticeDismissals.php:15`: drop "Fixture-only keys never persist."
- Modify: `apps/admin/src/lib/api/dashboard.api.ts`: `getDashboard(params: { range: DashboardRange; refresh?: boolean })`, with no `scenario` and no comment about it.
- Modify: `apps/admin/src/lib/queries/dashboard.queries.ts`:
  - `overview: (range: DashboardRange) =>` with query key `['dashboard', 'overview', range]`.
  - `useDashboardQuery(range: DashboardRange)`.
- Modify: `apps/admin/src/pro/dashboard/dashboard-pro.api.ts`: `getDashboardPro(params: { range: DashboardRange })`.
- Modify: `apps/admin/src/pro/dashboard/dashboard-pro.queries.ts`:
  - `overview: (range: DashboardRange) =>` with query key `['dashboard', 'pro', range]`.
  - `useDashboardProQuery(range: DashboardRange)`.
- Modify: `apps/admin/src/pro/dashboard/DashboardProPanels.tsx`: drop the `scenario` prop.
- Modify: `apps/admin/src/pages/dashboard/DashboardPage.tsx`: drop the `useDevScenario` import and call, the docblock's `?scenario=` line, and `scenario={scenario}`.
- Modify: `apps/admin/src/lib/schema/dashboard.schema.ts:92-99`: `DASHBOARD_NOTICE_KEYS = ['negative_balance', 'default_campaign_paused', 'points_jump']`, with docblock "The three server detectors." Keep `DISMISSABLE_NOTICE_KEYS`: it mirrors `NoticeDismissals::DISMISSABLE`.
- Modify: `apps/admin/src/lib/schema/dashboard-notice.schema.ts`:
  - Delete `mailFailuresDataSchema`, `balanceRebuildDataSchema`, their types, their `TypedNotice` members and their `DATA_SCHEMAS` entries.
  - The docblock ends at "the per-key `notice.data` shapes."
- Modify: `apps/admin/src/pages/dashboard/dashboard-page.notice-copy.ts`: delete the `mail_failures` and `balance_rebuild` cases in `copyFor()` and `noticeSummary()`, and drop imports this leaves unused (`formatStoredDate`).
- Modify: `apps/admin/src/pages/dashboard/components/DashboardNoticeStrip.tsx:40-44`: delete the non-dismissable guard and its toast, and drop the now-unused `DISMISSABLE_NOTICE_KEYS` import.

**Interfaces:**
- Consumes: nothing
- Produces: `useDashboardQuery(range)`, `useDashboardProQuery(range)` and `DashboardProPanels({ range, stats, children })`. Task 3 edits `DashboardProPanels`.

- [ ] **Step 1: Make the PHP deletions and edits, and lint them**

Run: `php -l includes/Controllers/DashboardRestController.php && php -l includes/Pro/Controllers/DashboardProRestController.php && vendor/bin/phpcs includes/Controllers/DashboardRestController.php includes/Pro/Controllers/DashboardProRestController.php includes/Services/Dashboard/NoticeDismissals.php`
Expected: `No syntax errors detected` twice, and no phpcs output

- [ ] **Step 2: Verify the endpoint ignores `scenario`**

Run: `WP eval 'wp_set_current_user(1); $r = new WP_REST_Request("GET", "/yayloyalty/v1/dashboard"); $r->set_query_params(["scenario" => "large"]); $d = rest_do_request($r)->get_data(); echo $d["stats"]["members"]["value"];'`
Expected: the site's real member count, not `250000`, and no PHP error

- [ ] **Step 3: Make the SPA edits for `scenario`**

- [ ] **Step 4: Make the SPA edits for the notice keys**

- [ ] **Step 5: Type-check and lint the SPA**

Run from `apps/admin`: `node node_modules/typescript/bin/tsc --noEmit`, then ESLint and Prettier on the changed `.ts`/`.tsx` files.
Expected: tsc prints only the baseline TS2307 errors. ESLint and Prettier report nothing new.

- [ ] **Step 6: Verify the dashboard in the browser** (Pro, dev server running)

Open: `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty#/?scenario=large`
Expected: real figures, not 250,000 members. The notice strip shows only real notices, and dismissing one still works.

- [ ] **Step 7: Commit**

Stage: the two deleted files, plus every modified file listed above
Message: `chore(dashboard): remove the dev scenario fixtures and fixture-only notices`

### Task 3: Pro dashboard dummy referrals and tiers

**Files:**
- Delete: `apps/admin/src/pro/dashboard/dashboard-pro-dummy.ts`
- Modify: `apps/admin/src/pro/dashboard/DashboardProPanels.tsx`:
  - `referralsTile` is the `DashboardStatTile` only when `data?.referrals` is non-null, otherwise `null`.
  - `sidePanels` renders `<DashboardTierDistribution tiers={…} />` only when `data.tier_distribution` is non-null.
  - `ProSidePanelsSkeleton` draws one card, Top campaigns. Its docblock says so.
- Modify: `apps/admin/src/lib/schema/dashboard-pro.schema.ts:8,18`: "the SPA substitutes its dummy" becomes "the SPA hides its panel".
- Modify: `apps/admin/src/pages/dashboard/dashboard-page.pro-slots.ts`: `referralsTile` doc reads "Sixth tile, appended to the stats grid; null hides it."

**Interfaces:**
- Consumes: `DashboardProPanels` without `scenario` (Task 2)
- Produces: nothing

- [ ] **Step 1: Make the edits listed above**

- [ ] **Step 2: Verify in the browser** (Pro build)

Open: `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty#/`
Expected: five stat tiles and no Referrals tile. The side column shows Top earners, then Top campaigns, with no Tier distribution card. While loading, the skeleton shows one card under Top earners.

- [ ] **Step 3: Type-check and lint as in Task 2, Step 5**

Expected: same result as Task 2, Step 5

- [ ] **Step 4: Commit**

Stage: `apps/admin/src/pro/dashboard/dashboard-pro-dummy.ts`, `apps/admin/src/pro/dashboard/DashboardProPanels.tsx`, `apps/admin/src/lib/schema/dashboard-pro.schema.ts`, `apps/admin/src/pages/dashboard/dashboard-page.pro-slots.ts`
Message: `chore(dashboard): hide Pro referrals and tiers instead of showing dummy figures`

### Task 4: Simulate-rebuild toggle

**Files:**
- Delete: `apps/admin/src/pages/customers/components/CustomersRebuildBanner.tsx`
- Modify: `apps/admin/src/pages/customers/CustomersPage.tsx`: drop the `isRebuilding` state, the `actions` button (and with it the `RefreshCw` and `Button` imports if unused), the `isRebuilding` prop, and the docblock's toggle clause.
- Modify: `apps/admin/src/pages/customers/components/CustomersScreen.tsx`:
  - Drop the `isRebuilding` prop and the banner.
  - `controlsDisabled = isStoreEmpty`.
  - `showSelection = IS_PRO_BUILD`.
  - Drop `staleBalances`.
- Modify: `CustomersTable.tsx`, `CustomersCardList.tsx`, `CustomerTableRow.tsx` and `CustomerCardRow.tsx` in `apps/admin/src/pages/customers/components/`: drop the `staleBalances` / `stale` props and the rebuild comments (`CustomersTable.tsx:55`, `CustomerTableRow.tsx:28`).
- Modify: `apps/admin/src/pages/customers/components/CustomersTableCells.tsx:27-36`: `BalanceCell({ value }: { value: number })`, always `font-semibold`. Drop the `stale` doc line.
- Modify: `apps/admin/src/pages/customers/components/CustomersToolbar.tsx:28`: "Empty store: controls go inert but stay in place, so the table does not shift."

**Interfaces:**
- Consumes: nothing
- Produces: `CustomersScreen` without `isRebuilding`, and `BalanceCell({ value })`

- [ ] **Step 1: Make the deletions and edits listed above**

- [ ] **Step 2: Verify no reference is left**

Run: `git grep -n -i -E 'rebuild|stale' -- apps/admin/src/pages/customers`
Expected: only the Adjust points stale-balance feature (`isStale`, `AdjustPointsStaleBalanceBanner`, `stale*` copy) and the stale-rows comments in `CustomersPage.tsx` and `customers-page.bulk-actions.ts`, which are unrelated to the rebuild toggle

- [ ] **Step 3: Type-check and lint as in Task 2, Step 5**

Expected: same result as Task 2, Step 5

- [ ] **Step 4: Commit**

Stage: the deleted banner and every modified file listed above
Message: `chore(customers): remove the Simulate rebuild demo toggle`

### Task 5: Sample tiers on the Customers page

**Files:**
- Delete: `apps/admin/src/pages/customers/customers-page.tier-sample-data.ts`, `apps/admin/src/pages/customers/drawer/CustomerDrawerTierCard.tsx`
- Modify: `apps/admin/src/pages/customers/drawer/CustomerDrawerHeader.tsx`: drop line 41 and the `CustomerDrawerTierCard` and `isProBuild` imports.
- Modify: `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`: delete `tierProgress` and `TOP_TIER_REACHED_LABEL`, and drop `sprintf` from the import if unused.
- Modify: `apps/admin/src/pages/customers/customers-page.filter-rows.ts`:
  - `RowFilters` is `{ search; balanceRange; expiringSoon }`, and `DEFAULT_ROW_FILTERS` matches.
  - Delete `TierValue`, `TIER_FILTER_ENABLED` and `TIER_OPTIONS`.
- Modify: `apps/admin/src/pages/customers/customers-page.filter-suggestions.ts`:
  - `FilterKey = 'search' | 'balanceRange' | 'expiringSoon'`.
  - Drop `tier` from `FILTER_KEY_BY_WIRE_KEY`.
  - `countActiveFilters(filters: RowFilters): number`, with no `includeTier` (the same for `activeFilterKeys`).
  - Delete the `'tier'` case in `describeFilterValue`.
  - `toFilterDropSuggestions` maps the wire key directly, and its docblock drops the relabel sentence.
- Modify: `apps/admin/src/lib/schema/customer.schema.ts`:
  - `noMatchSuggestionSchema.key` is `z.enum(['search', 'balance_range', 'expiring_soon'])`.
  - Delete `CustomerTier`.
  - `CustomerSortKey` drops `'tier'`. Its doc reads "The server does not sort by `referrals` yet."
- Modify: `apps/admin/src/lib/api/customer.api.ts`: drop `CustomerTier` from imports, `tier?` from `CustomerListParams` and the `tier` line in `getCustomers`. The `previewAdjustPoints` docblock ends at "respells camelCase as snake_case."
- Modify: `CustomersPage.tsx`, `components/CustomersScreen.tsx` and `customers-page.matching-customers.ts`: spread `filters` / `queryFilters` / `{ filters: queryFilters }` where `toServerFilters(…)` was. Call `countActiveFilters(filters)` with one argument.
- Modify: `apps/admin/src/pages/customers/components/CustomersToolbar.tsx`:
  - Delete the Tier select.
  - The Balance range `onValueChange` patches only `{ balanceRange }`.
  - The docblock drops the Tier/Balance exclusivity sentence.
- Modify: `apps/admin/src/pages/customers/components/CustomersTable.tsx`:
  - Delete the Tier `SortableHead`.
  - `countColumns` returns `6 + (showProColumns ? 1 : 0) + (showSelection ? 1 : 0)`. Its comment names one Pro column, Referrals.
- Modify: `CustomerTableRow.tsx` and `CustomerCardRow.tsx`: delete the Tier cell and badge, `sampleTierForBalance`, and the docblock's sample-tier line.
- Modify: `customers-page.sort-rows.ts:9`: `key === 'name' ? 'asc' : 'desc'`.
- Modify: `components/CustomersSortDropdown.tsx`: drop the `tier` label.
- Modify: `components/adjust-points/AdjustPointsBulkPanels.tsx:23`: "The active filters as text."
- Modify (added during implementation): `apps/admin/src/pro/customers/customer-segments.api.ts`, `apps/admin/src/pro/customers/customers-saved-segment-row.state.ts` and `apps/admin/src/types/localize.type.ts`. Saved segments drop `tier` from their app shape (`savedSegmentSchema`, `RowFilters`). The wire schema and `AdminData.segments` keep `tier` (as `string`) to mirror `CustomerSegmentsHelper::FILTER_SCHEMA`: `fromWire` drops it, and `toWireFilters` sends `'all'`.
- Modify (added during implementation): `includes/Controllers/CustomerAdjustPreviewRestController.php:55-56`: the comment naming `toServerFilters` becomes a one-line reason for the missing `tier`.

**Interfaces:**
- Consumes: `CustomersScreen` without `isRebuilding` (Task 4)
- Produces: `RowFilters = { search: string; balanceRange: BalanceRangeValue; expiringSoon: ExpiringSoonValue }` and `countActiveFilters(filters: RowFilters): number`

- [ ] **Step 1: Make the deletions and edits listed above**

- [ ] **Step 2: Type-check and lint as in Task 2, Step 5**

Expected: same result as Task 2, Step 5. A missed `tier` shows up as a TS error, which is why this runs before the browser check.

- [ ] **Step 3: Verify filters in the browser** (Pro build, DevTools Network tab open)

Open: `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty#/customers?balance_range=negative`
Expected:
- The Balance range filter shows Negative, and the `GET /customers` request carries `balance_range=negative` and no `tier`.
- There is no Tier column, no Tier filter and no Simulate rebuild button.
- With Balance range set to `0 – 1,000`, Export CSV's `GET /customers` requests and Adjust points (all matching) → `POST /customers/adjust/preview` carry `balance_range: "0-1000"`. The preview count matches the list total.
- Opening a customer shows a drawer header with no tier card.

- [ ] **Step 4: Commit**

Stage: the two deleted files and every modified file listed above
Message: `chore(customers): remove the sample tier column, filter and drawer card`

### Task 6: Unread fixtures and the dump script

**Files:**
- Delete: `scripts/dump-design-defaults.php`, `apps/admin/tests/fixtures/design-defaults.json`, `tests/fixtures/points-math-vectors.json`
- Modify: `apps/admin/src/lib/points-math/points-math.ts:4` and `includes/Utils/PointsMath.php:9`: delete the line naming the vectors file.
- Modify: `docs/architecture.md:186-188`: the Shared fixtures bullet keeps the rule, "`tests/fixtures/` holds data that both languages test against, with PHP as the source of truth." It drops the example and the dump-script line.

**Interfaces:**
- Consumes: nothing
- Produces: nothing

- [ ] **Step 1: Make the deletions and edits listed above**

- [ ] **Step 2: Verify**

Run: `git grep -n -E 'dump-design-defaults|design-defaults\.json|points-math-vectors' -- ':!docs/plans' ':!docs/specs'`, then `php -l includes/Utils/PointsMath.php`
Expected: the grep prints nothing; `php -l` prints `No syntax errors detected`

- [ ] **Step 3: Commit**

Stage: the three deleted files, `apps/admin/src/lib/points-math/points-math.ts`, `includes/Utils/PointsMath.php`, `docs/architecture.md`
Message: `chore(tests): remove unread fixtures and the design-defaults dump script`

### Task 7: End-to-end check

- [ ] **Step 1: Acceptance grep**

Run: the `git grep` in Acceptance criterion 1
Expected: no output

- [ ] **Step 2: Lite leak check**

Run: `git grep -n "from '@/pro/" -- apps/admin/src ':!apps/admin/src/pro'`
Expected: only the lines that are already on `dev/edd` (compare with `git grep … dev/edd -- …`), and nothing new

- [ ] **Step 3: Full type-check and PHP lint**

Run: from `apps/admin`, `node node_modules/typescript/bin/tsc --noEmit`; from the repo root, `composer lint`
Expected: tsc prints only the baseline TS2307 errors. `composer lint` prints only the existing warnings in `CampaignConditionSet.php`, `RewardConditionSet.php` and `CustomerRowFilters.php`.

- [ ] **Step 4: Lite build in the browser**

When implemented, the Pro dev server was in use, so this ran as a scratch `BUILD_TYPE=lite` `vite build` instead. The build succeeded. The bundle held no `dashboard/pro`, `customers/segments`, rebuild or tier-card strings, only Lite's teaser labels. The visual check below is still open.

Run: `./scripts/run.sh dev lite` and wait for the admin-shell sync to finish. Then open `#/` and `#/customers`.
Expected: the dashboard shows the Lite teasers unchanged (Referrals `—`, Tier distribution and Top campaigns teaser cards). Customers has no Tier or Referrals column. No console errors. Afterwards, `git status` shows no change to `composer.json` or `scoper.inc.php`, or you restore them.
