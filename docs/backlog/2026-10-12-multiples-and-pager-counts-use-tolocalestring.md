# Three admin strings format numbers with `toLocaleString()`

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-12 docs-sync audit of `dev/edd` @ `656cbbf`, branch `trace/docs/sync-docs-with-code-2`.

## Today

[docs/conventions.md § Money](../conventions.md) says points and numbers use WooCommerce's separators through `formatStoreNumber`, `formatStoreDecimal`, `formatPercent` or `formatPoints` (`apps/admin/src/lib/helpers/format.helper.ts`), never `toLocaleString()`. These still call `toLocaleString()`, so they follow the browser locale:

- `pagerSummary()` in `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`, for `from`, `to` and `total`;
- the anomaly notice title in `apps/admin/src/pages/dashboard/dashboard-page.notice-copy.ts`, for `n.data.multiple`;
- `DashboardAnomalySettlements` in `apps/admin/src/pages/dashboard/components/DashboardAnomalySettlements.tsx`, for `threshold.multiple`.

`components/ui/chart.tsx` and `components/ui/calendar.tsx` also call it, but they are vendored UI kit files and out of scope.

## Why it matters

A store whose separators differ from the merchant's browser locale (for example a comma decimal) shows "3.2×" in one place and "3,2" in another, and pager counts with the wrong thousands separator.

## Suggested fix

Use `formatStoreNumber` for the pager counts and `formatStoreDecimal` for the multiples.

## Done when

- [ ] `git grep -n toLocaleString -- apps/admin/src/pages` prints nothing.
