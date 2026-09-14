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
 */
export function computeSelectAllCount(
  sortedRows: MockCustomer[],
  selected: Set<number>,
  pageCount: number,
): number | undefined {
  const allFilteredSelected = sortedRows.length > 0 && sortedRows.every((row) => selected.has(row.user_id));
  return pageCount > 1 && selected.size > 0 && !allFilteredSelected ? sortedRows.length : undefined;
}
