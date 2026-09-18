# Phase 01 — Activity log tab (3d)

## Context Links

- Brief → [`brainstorm-260918-0845-customer-drawer-wave-b.md`](../reports/brainstorm-260918-0845-customer-drawer-wave-b.md)
- Artboard **3d**; row shape recorded in [`docs/data-model.md`](../../docs/data-model.md) → "Activity log (not designed)"
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerActivity.php` — **already written, 104 lines, dead code**
- `includes/Controllers/LedgerRestController.php` — the controller shape this copies
- `includes/Controllers/Schemas/LedgerQueryArgs.php` — the query-args shape this copies
- `includes/Engine/RestAPI.php:72` — where controllers are constructed and registered
- `apps/admin/src/lib/schema/ledger.schema.ts` — the Zod mirror shape this copies
- `apps/admin/src/pages/customers/drawer/customer-drawer.ledger-rows.ts` — the "compose sentences from structured fields" pattern
- Wave A phase 06 for the tab-body composition pattern (filters → rows → pager)

## Overview

- Priority: P1
- Status: pending
- The second tab, visible in **both** builds, is the only drawer tab a Free user can see that is
  still empty. Its fixture exists; only a route, a schema and a UI are missing.

## Key Insights

- **`DrawerActivity.php` is already written and nothing calls it.** Wave A wrote it so the row shape
  was decided while the design was in hand, then deliberately did not wire it. This phase is
  mostly *connecting* existing work, which is why it is cheapest-first despite being a whole tab.
- **Load more, not paged** (3d: "7 of 24 events"). The ledger is paged because its running-balance
  column depends on it (3c note 3); the activity log has no such column, so the artboard's Load
  more stands. This is the one place the two tabs deliberately diverge — do not "harmonise" them.
- **The filter groups overlap on purpose.** `expiring_soon_email_sent` belongs to both *Expiry* and
  *Emails*. It is a lens ("what am I looking for"), not a partition. A row may appear under two
  groups; that is correct.
- **No table exists, and this phase does not add one.** The real path returns an empty envelope —
  honestly, because there genuinely are no activity records. That matches `counts.activity = 0` in
  `CustomerRestController::format_customer()`, which is already truthful.
- `staff_only` means "this row quotes an internal admin note". It is a display flag, not an
  access-control flag — the whole endpoint is already `CAP::VIEW`-gated. Never use it to filter.
- 3d's footer note ("Points movements are not repeated here — they live in the points ledger") is
  load-bearing copy, not decoration: it is what stops an admin hunting for an adjustment here.

## Requirements

Functional
1. `GET /customers/{id}/activity` returns a paginated envelope of activity rows, `CAP::VIEW`-guarded.
2. Under `WP_DEBUG` + `?scenario=`, it answers from `DrawerActivity`; otherwise an empty envelope.
3. A `group` arg filters to All / Admin actions / Tier changes / Expiry / Emails.
4. The tab renders Date (**with time**), Event (title + structured sub-line), By (actor name or
   "Automatic"/"Customer", plus the `STAFF ONLY` badge and an envelope glyph on email rows).
5. "N of M events" + **Load more**, accumulating pages rather than replacing them.
6. The 3d footer note renders below the rows.
7. Empty state stays exactly as Wave A shipped it when there are no rows.

Non-functional
- Every file < 200 lines.
- Every sentence composed client-side from `event_type` + `data`. The fixture stores no prose.
- Figures unabbreviated.

## Architecture

```
includes/Controllers/
  ActivityRestController.php        NEW — GET /customers/{id}/activity
  Schemas/ActivityQueryArgs.php     NEW — group enum + group→event_type map
  Fixtures/CustomerDrawer/
    CustomerDrawerFixture.php       MODIFY — add activity() dispatcher
    DrawerActivity.php              MODIFY — add envelope() with group filter + paging

apps/admin/src/
  lib/schema/activity.schema.ts     NEW — Zod mirror
  lib/api/customer.api.ts           MODIFY — getCustomerActivity()
  lib/queries/customer.queries.ts   MODIFY — useCustomerActivityQuery (infinite)
  pages/customers/drawer/
    customer-drawer.activity-rows.ts   NEW — event_type + data → title/subLine/actor
    customer-drawer.copy.ts            MODIFY — every activity sentence
    tabs/ActivityLogTab.tsx            REWRITE — owns group state + query
    tabs/ActivityLogTabFilters.tsx     NEW — the Show: segmented group
    tabs/ActivityLogTabRows.tsx        NEW — table + STAFF ONLY badge
```

A separate controller, not a method on `CustomerRestController`: that is exactly how
`LedgerRestController` handles `/customers/{id}/ledger`, and it keeps both files under the cap.

## Related Code Files

Create
- `includes/Controllers/ActivityRestController.php`
- `includes/Controllers/Schemas/ActivityQueryArgs.php`
- `apps/admin/src/lib/schema/activity.schema.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.activity-rows.ts`
- `apps/admin/src/pages/customers/drawer/tabs/ActivityLogTabFilters.tsx`
- `apps/admin/src/pages/customers/drawer/tabs/ActivityLogTabRows.tsx`

Modify
- `includes/Controllers/Fixtures/CustomerDrawer/CustomerDrawerFixture.php`
- `includes/Controllers/Fixtures/CustomerDrawer/DrawerActivity.php`
- `includes/Engine/RestAPI.php` (construct + register the new controller)
- `apps/admin/src/lib/api/customer.api.ts`
- `apps/admin/src/lib/queries/customer.queries.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`
- `apps/admin/src/pages/customers/drawer/tabs/ActivityLogTab.tsx`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerTabs.tsx` (pass `userId`/`scenario` through)

Delete
- none

## Implementation Steps

1. `ActivityQueryArgs.php`: `GROUPS = ['all','admin','tier','expiry','emails']`, an `all()` returning
   the `group` arg descriptor, and `event_types_for( string $group ): array` holding the mapping:

   | group | event_type |
   |---|---|
   | `admin` | `points_adjusted`, `expiry_reset` |
   | `tier` | `tier_changed` |
   | `expiry` | `expiry_reset`, `expiring_soon_email_sent` |
   | `emails` | `balance_email_sent`, `expiring_soon_email_sent` |

   `all` returns every type. Document the overlap in the docblock so nobody "fixes" it later.
2. `DrawerActivity::envelope( $scenario, $user_id, $args )`: reuse the existing `rows()`, filter by
   `event_types_for($group)`, then slice by `page`/`per_page`. Return
   `{ items, total, page, per_page }` — `total` is the **filtered** total (what "N of M" counts),
   while the tab label keeps using the unfiltered `counts.activity`.
3. `CustomerDrawerFixture::activity()` — a one-line dispatcher mirroring `ledger()`.
4. `ActivityRestController`: `register_routes()` copied from `LedgerRestController`, args =
   `id` + `get_collection_params()` + `ActivityQueryArgs::all()` + `CustomerDrawerFixture::scenario_arg()`.
   Handler: fixture branch under `WP_DEBUG` + `?scenario=`, else an **empty envelope**
   (`items: [], total: 0`) with a docblock saying zero is the true count because no table exists.
   Add `get_item_schema()` describing the row — it is the future contract.
5. `RestAPI.php`: construct and register it beside `LedgerRestController`. It takes no repository.
6. `activity.schema.ts`: mirror the PHP item schema — `ACTIVITY_EVENT_TYPES` (the seven),
   `ACTIVITY_ACTOR_KINDS` (`staff`/`automatic`/`customer`), `ACTIVITY_GROUPS`, `activityEntrySchema`
   with `data: z.record(z.unknown())`, and `activityEnvelopeSchema = paginatedSchema(...)`.
   Point the docblock at the PHP method as source of truth, exactly as `ledger.schema.ts` does.
7. `customer.api.ts`: `getCustomerActivity(userId, params)` parsing with `activityEnvelopeSchema`.
8. `customer.queries.ts`: an **infinite** query (`useInfiniteQuery`) keyed
   `['customer','activity',userId,params]`, `getNextPageParam` returning `undefined` once
   `page * per_page >= total`. This is the app's first infinite query — keep it local to this key,
   do not generalise a helper for one call site.
9. `customer-drawer.activity-rows.ts`: `toActivityRow(entry)` → `{ date, time, title, subLine,
   actorLabel, isStaffOnly, isEmail }`. Every string comes from the copy module; this file only
   chooses which copy function to call and passes `data`'s fields into it. Mirror
   `customer-drawer.ledger-rows.ts`.
10. `customer-drawer.copy.ts`: one function per `event_type` sub-line (adjusted → points + quoted
    note; tier_changed → from → to + automatic/manual + threshold; expiry_reset → from → to +
    quoted note; reward_redeemed → reward + order; account_created → campaign + points;
    balance_email_sent → subject; expiring_soon_email_sent → window days + deadline), plus the
    five group labels, the column headers, the `STAFF ONLY` label, "Automatic"/"Customer",
    `activityCountSummary(loaded, total)` and the footer note.
11. `ActivityLogTabRows.tsx`: three columns, `STAFF ONLY` badge and envelope glyph in the By cell.
    Reuse `LedgerTabRows.tsx`'s below-`sm` wrap technique rather than inventing a second one.
12. `ActivityLogTabFilters.tsx`: the same `Segmented` control the ledger filter now uses, in a
    scrolling wrapper — copy that composition, it is already correct (see Wave A phase 06's
    post-ship notes for why the wrapper matters).
13. `ActivityLogTab.tsx`: owns `group` state, calls the infinite query, composes filters → rows →
    "N of M events" + Load more → footer note. Changing the group resets to page 1.
14. `CustomerDrawerTabs.tsx`: pass `userId` and `scenario` into `<ActivityLogTab/>`.

## Todo List

- [x] `ActivityQueryArgs` with the documented overlapping group map
- [x] `DrawerActivity::envelope()` filters + slices; `total` is the filtered total
- [x] `ActivityRestController` registered; real path returns an empty envelope, not an error
- [x] `get_item_schema()` describes the row (it is the future table's contract)
- [x] Zod mirror parses fixture and real (empty) responses identically
- [x] Infinite query accumulates pages; Load more disappears on the last page
- [x] Every sentence composed client-side; fixture stores no prose
- [x] `STAFF ONLY` badge on the two note-carrying event types only
- [x] Footer note renders
- [x] Empty state unchanged when there are no rows
- [x] Every new file < 200 lines

Deviations from plan:
- **`DrawerActivity::data_for()` gained one field**: `tier_changed` now carries `threshold: 5000`.
  3d's exact text is "Explorer → Summit · automatic · **reached 5,000 lifetime points**" — the
  fixture written in Wave A had no field for that number, so the sub-line would have had to
  either invent it (banned — structured fields only) or drop it (unfaithful to the artboard). One
  structured integer, not prose; the smallest fix that keeps the row shape matching the design it
  was decided from.
- **`expiring_soon_email_sent`'s "deadline" is derived client-side**, not a new fixture field.
  3d's copy is "30-day reminder · deadline was 21 Sept 2026". The fixture only carries
  `window_days`; the deadline is `occurred_at + window_days` days, computed in
  `customer-drawer.activity-rows.ts` — a deterministic derivation from fields already on the row
  (same category as the tier card's `pointsToNext` or the negative-balance panel's share-of-
  balance calc elsewhere in this wave), not a fabrication.
- **Pager is "Load more" via a `<Button variant="link">`**, not a reused pager component. The
  ledger's `LedgerTabPager.tsx` is a real paged control (page numbers, ‹ Newer/Older ›) — wrong
  shape for accumulate-and-grow. Nothing to extract; one button.
- **`ActivityLogTabRows.tsx` takes no `customerFirstName` prop**, unlike `LedgerTabRows.tsx`.
  `EMPTY_ACTIVITY_TITLE`/`EMPTY_ACTIVITY_BODY` (already written in Wave A) have no name
  interpolation slot — 3d's empty copy doesn't name the customer. Accepting an unused prop "for
  parity" would just be dead code.
- **Group enum is not strictly validated** (`group=bogus` returns 200, degrades to `all` via
  `event_types_for()`'s `default: return null`). Confirmed against the existing sibling
  `LedgerQueryArgs::filter_types()`'s `type` arg, which has the same non-strict behavior (no
  `validate_callback`) — consistent with, not a regression from, the pattern being mirrored.
- **Invalid user id returns HTTP 400, not 404** (`user_id_param()`'s `validate_callback` returns a
  404 `WP_Error`, but WP core's `has_valid_params()` aggregates every arg validation failure into
  one `rest_invalid_param` 400, discarding the individual error's own status). Confirmed identical
  on `LedgerRestController` — pre-existing behavior in shared `AbstractRestController` code, not
  something this phase introduced or could fix without touching a file outside its scope.

Live-verified via `rest_do_request()` (admin impersonation): real path returns an empty envelope
(`totalItems: 0`, `status: 200`); `?scenario=large` returns 1,940 rows total; `?scenario=deleted`
group filters (`admin`/`tier`/`expiry`/`emails`) each return the expected event-type subset;
`expiring_soon_email_sent` confirmed present in BOTH `expiry` and `emails` groups (554 rows each on
`large`) — the overlap is real and correct; page 1 vs page 2 (`per_page=5`) return disjoint id sets,
confirming Load more accumulation math; `tier_changed`'s new `threshold` field round-trips correctly.

**No live browser verification was possible** — this environment has no browser-automation tool and
the dev server port (3030) was occupied. Verified instead: `tsc --noEmit` (no new errors — same
five pre-existing, unrelated errors as Wave A's phases), ESLint clean on every touched/new file,
Vitest 63/63, and a full production `vite build` (8,475 modules, zero bundler errors) — which does
catch invalid imports/JSX/exports across every new and modified file here. A real click-through
(switch groups, Load more, verify the 782px stack) is deferred to the user's manual pass, exactly as
Wave A's own phases documented for the same environment limitation.

## Code Review

Two findings, both fixed:
- **`formatPoints()` misapplied to `order_id`** in `customer-drawer.activity-rows.ts`'s
  `reward_redeemed` sub-line — grouped-thousands formatting on an id would have printed
  "order #4,098" instead of "order #4098". Changed to `String(...)`, matching
  `describeLedgerEntry()`'s own order-number formatting for the identical field.
- **Stale docblock in `CustomerDrawerTabs.tsx`** still said "Only the ledger tab has a real,
  working body" two lines above the new `<ActivityLogTab userId={...} scenario={...} />` call —
  left over from Wave A. Updated to name Activity log as real/fixture-backed too.

One flagged, not acted on: `customer-drawer.copy.ts` is at 191/200 lines. Phase 02 will very
likely push it over. Recorded here as a heads-up for phase 02's own planning, not fixed
speculatively now — splitting it before there is a concrete second file's worth of exports would
be guessing at the split boundary.

Re-verified after fixes: ESLint clean, `tsc --noEmit` no new errors, Vitest 63/63, `vite build`
clean.

## Post-ship amendment: Load more → full ledger-style filtering

The user reviewed the shipped tab against `?scenario=large` (1,940 rows) and reported it hard to
browse with only a group filter and Load more. Requested explicit parity with the ledger tab
(paged + search + date range) rather than a partial fix, after being told this diverges from 3d's
literal control (Load more, group filter only, no search/date range) and why that control was
chosen (no running-balance column forcing contiguous pages the way the ledger has). The reasoning
for 3d's choice was never wrong — it just didn't anticipate what 1,940 rows feel like without a
search box. Confirmed and implemented:

- **`ActivityQueryArgs`** gained `search`/`after`/`before`, field-for-field copies of
  `LedgerQueryArgs::all()`'s own args (same descriptions, same `validate_date` reused directly
  from `LedgerQueryArgs::class` rather than duplicated — same namespace, no `use` needed).
- **`DrawerActivity::envelope()`** gained `apply_filters()` + `matches_search()`, mirroring
  `DrawerLedger::apply_filters()`'s exact shape (lower-cased/trimmed search, `Y-m-d`-prefix date
  comparison). Search matches `actor_name` plus every string value inside `data` — the row's
  searchable text is nested rather than top-level, unlike the ledger's `note`/`order_id`, so this
  flattens `data` into the search haystack rather than naming individual fields.
  `ActivityRestController` and `CustomerDrawerFixture::activity()` pass the three new args through
  unchanged.
- **The infinite query is gone.** `useCustomerActivityQuery` is now a plain paginated `useQuery`
  (`CUSTOMER_QUERIES.activity`), identical in shape to `CUSTOMER_QUERIES.ledger`. This fully
  reverses this phase's original "first infinite query in the app" — there is currently no
  infinite query anywhere in the codebase again. `LOAD_MORE_LABEL`/`activityCountSummary` were
  removed from the copy module (dead code once nothing calls them) and replaced by
  `ACTIVITY_SEARCH_PLACEHOLDER`.
- **`LedgerTabPager.tsx` renamed to `DrawerTabPager.tsx`** (and its export renamed to match) —
  it was already fully generic (no ledger-specific data or copy), so extracting a second copy for
  Activity would have duplicated a component that needed nothing changed to be shared. Single
  import-site rename (`LedgerTab.tsx`), done rather than leaving a misleadingly-named shared file.
- **`ActivityLogTabFilters.tsx`** gained the search input + `DateRangePicker`, same two-row layout
  and `Segmented`-in-a-scrolling-wrapper composition as `LedgerTabFilters.tsx`. Unlike the ledger's
  type filter, group segments carry no per-group counts (3d draws none) and nothing here ever
  disables: the real path's `0` total is genuinely true regardless of what the filters are set to
  — there is no "server can't filter yet" signal to represent, unlike the ledger's
  `type_counts === null` state.
- **`ActivityLogTab.tsx`** rewritten to the same state shape as `LedgerTab.tsx`
  (`group`/`range`/`search`/`querySearch`/`page`), reusing `useDebouncedSearchCommit` and
  `DrawerTabPager` rather than inventing either again.

Live-verified via `rest_do_request()`: search matches on `actor_name` (554 rows for "sarah",
exactly the `admin` group's total) and on a `data` string value ("goodwill" → 277, half of 554 —
the two staff event types alternate under the fixture's `i % 7` cycling); no-match search returns
0; a 7-day `after`/`before` range returns 8 rows (one per day, inclusive both ends); group + search
+ date range combine correctly (AND, not OR); page 1 vs page 2 return disjoint id sets; the real
path stays an empty, `200`-status envelope regardless of which filters are sent.

Re-verified after this amendment: ESLint clean, `tsc --noEmit` no new errors, Vitest 63/63,
`vite build` clean. All files stay under the 200-line cap; `customer-drawer.copy.ts` is now at
192/200 — still not split, flagged again for phase 02.

### Review of the amendment

One High finding, fixed: `matches_search()` only matched `is_string()` values inside `data`,
silently excluding `reward_redeemed`'s `order_id` (an int) — an asymmetry with the ledger, whose
own search incidentally matches `order_id` through PHP's implicit string concatenation. Changed
the guard to `is_string() || is_int()`, deliberately still excluding bools (`tier_changed`'s
`automatic`) — PHP stringifies `true`/`false` as `"1"`/`""`, which would make a bare "1" search
match every automatic tier-change row. Live-verified: `search=4098` now matches the
`reward_redeemed` row carrying that `order_id` (277 rows — same alternating-type count pattern as
every other single-event-type search on the `large` scenario); `search=1` with `group=tier` still
returns 0, confirming the bool exclusion holds. Also tightened the `search` arg's description to
name what it actually matches (was "the event and who did it", narrower than the real scope, which
includes reward/campaign/subject text too).

Everything else the reviewer checked came back clean: the `LedgerTabPager` → `DrawerTabPager`
rename left no stale references anywhere in the codebase; `useDebouncedSearchCommit` wiring matches
`LedgerTab.tsx` line-for-line; no leftover infinite-query imports/exports anywhere; the queryKey
shape causes no unnecessary refetch churn; searching into `data`'s note/subject/reward/campaign
strings is a defensible reuse of text the row's own copy already renders verbatim, not a new prose
leak.

## Success Criteria

- `?scenario=` (24 rows), `large` (1,940) and `new` (0) each render 3d correctly.
- Tab label count and the "of M" figure agree when no filter is applied, and the label keeps showing
  the unfiltered count when one is.
- Real path (no `?scenario=`) shows the empty state with a `0` tab count — consistent, not broken.
- Switching group refetches from page 1; Load more never duplicates a row.
- `composer lint`, `composer test`, `tsc --noEmit`, `vite build` all clean.

## Risk Assessment

- **Load more + filter interaction** — changing the group mid-accumulation must discard accumulated
  pages, not append to them. TanStack does this on a `queryKey` change; make `group` part of the key.
- **First infinite query in the app.** No precedent to copy. Keep it confined to this one key.
- **Overlapping groups look like a bug** to whoever reads the counts. Document in both the PHP map
  and the schema.
- **`DrawerActivity::rows()` cycles event types by index** (`$i % 7`), so a filtered group returns a
  regular, obviously-synthetic pattern. Acceptable for a fixture; do not mistake it for real data
  when reviewing.

## Security Considerations

- Route is `CAP::VIEW`-guarded server-side (authoritative); the SPA route guard is cosmetic.
- `staff_only` is a **display** flag. The endpoint is admin-only, so it must never be used to
  filter rows out — if a customer-facing view is ever built, that is a different endpoint.
- The fixture stays `WP_DEBUG` + `?scenario=` gated. Never serve it unconditionally.
- `{id}` is user-controlled; reuse `user_id_param()` so unknown users 404 as elsewhere.

## Next Steps

- Phase 02 adds the 4a panels above the tab bar.
- When a real activity table ships, only `ActivityRestController`'s handler changes — the schema,
  copy and UI are already the contract.
