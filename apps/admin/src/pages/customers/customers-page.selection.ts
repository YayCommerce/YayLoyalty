import type { MockCustomer } from './customers-page.mock-data';

/**
 * Pure row-selection math for CustomersScreen.tsx — split out to keep that
 * file under the project's 200-line cap. The `Set<user_id>` state itself
 * stays in the component; these just derive view state from it.
 */
export type HeaderSelectionState = 'none' | 'partial' | 'all';

/** The header checkbox's tri-state, scoped to the current page only. */
export function computeHeaderSelection(pageRows: MockCustomer[], selected: Set<number>): HeaderSelectionState {
  if (pageRows.length === 0) return 'none';
  const selectedOnPage = pageRows.filter((row) => selected.has(row.user_id)).length;
  if (selectedOnPage === 0) return 'none';
  if (selectedOnPage === pageRows.length) return 'all';
  return 'partial';
}

/**
 * How many rows the "Select all N matching these filters" link would add —
 * `undefined` when there's nothing to expand into (one page, nothing
 * selected yet, or everything already selected).
 *
 * `totalItems` is the server's count for the current filters (see
 * `CustomersPage.tsx`) across every page, not just the one that's loaded —
 * with real per-page fetching, "matching" only means something checked
 * against the whole (demo) dataset, the same reasoning as
 * `customers-page.filter-suggestions.ts`. No active filter is just the
 * `DEFAULT_ROW_FILTERS` case: `totalItems` is then the whole store, so this
 * naturally covers "select every member" too, not only "every match".
 */
export function computeSelectAllCount(selected: Set<number>, totalItems: number, pageCount: number): number | undefined {
  const allMatchingSelected = totalItems > 0 && selected.size >= totalItems;
  return pageCount > 1 && selected.size > 0 && !allMatchingSelected ? totalItems : undefined;
}
