import type { MockCustomer } from './customers-page.mock-data';

/**
 * Row-selection math for CustomersScreen.tsx — split out to keep that file
 * under the project's 200-line cap.
 *
 * Selection is either an explicit set of picked ids ('partial'), or "every
 * row matching the current filters, except these" ('all' with an exclusion
 * set) — the same trick Ant Design's Table (`rowSelection.selections`,
 * SELECTION_ALL/SELECTION_INVERT) and most admin tables use to offer "select
 * everything matching this search" without fetching or holding every
 * matching row's id in the browser: switching to 'all' is an O(1) flip, the
 * count comes from the server's `totalItems` (see CustomersPage.tsx), and
 * per-row state stays cheap to check regardless of how large the real match
 * ever gets.
 */
export type SelectionState = { mode: 'partial'; ids: Set<number> } | { mode: 'all'; excludedIds: Set<number> };

export const EMPTY_SELECTION: SelectionState = { mode: 'partial', ids: new Set() };

export function isRowSelected(state: SelectionState, userId: number): boolean {
  return state.mode === 'all' ? !state.excludedIds.has(userId) : state.ids.has(userId);
}

/** How many rows are actually selected — `totalItems` is the server's count for the current filters. */
export function selectionCount(state: SelectionState, totalItems: number): number {
  return state.mode === 'all' ? Math.max(0, totalItems - state.excludedIds.size) : state.ids.size;
}

export function toggleRowSelection(state: SelectionState, userId: number): SelectionState {
  if (state.mode === 'all') {
    const excludedIds = new Set(state.excludedIds);
    if (excludedIds.has(userId)) {
      excludedIds.delete(userId);
    } else {
      excludedIds.add(userId);
    }
    return { mode: 'all', excludedIds };
  }

  const ids = new Set(state.ids);
  if (ids.has(userId)) {
    ids.delete(userId);
  } else {
    ids.add(userId);
  }
  return { mode: 'partial', ids };
}

/** Header checkbox: toggles every row on THIS page only (paging keeps prior picks elsewhere). */
export function toggleAllOnPage(state: SelectionState, pageRows: MockCustomer[]): SelectionState {
  const allOnPageSelected = pageRows.length > 0 && pageRows.every((row) => isRowSelected(state, row.user_id));

  if (state.mode === 'all') {
    const excludedIds = new Set(state.excludedIds);
    pageRows.forEach((row) => (allOnPageSelected ? excludedIds.add(row.user_id) : excludedIds.delete(row.user_id)));
    return { mode: 'all', excludedIds };
  }

  const ids = new Set(state.ids);
  pageRows.forEach((row) => (allOnPageSelected ? ids.delete(row.user_id) : ids.add(row.user_id)));
  return { mode: 'partial', ids };
}

export type HeaderSelectionState = 'none' | 'partial' | 'all';

/** The header checkbox's tri-state, scoped to the current page only. */
export function computeHeaderSelection(pageRows: MockCustomer[], state: SelectionState): HeaderSelectionState {
  if (pageRows.length === 0) return 'none';
  const selectedOnPage = pageRows.filter((row) => isRowSelected(state, row.user_id)).length;
  if (selectedOnPage === 0) return 'none';
  if (selectedOnPage === pageRows.length) return 'all';
  return 'partial';
}

/**
 * How many rows the "Select all N matching these filters" link would add —
 * `undefined` when there's nothing to expand into: already in 'all' mode
 * (or a 'partial' pick that already covers every matching row), nothing
 * picked yet, or only one page total. Shows as soon as ANY row is picked —
 * not only once the whole page is — matching the design (picking a single
 * row already offers "Select all N matching these filters").
 */
export function computeSelectAllCount(state: SelectionState, totalItems: number, pageCount: number): number | undefined {
  const count = selectionCount(state, totalItems);
  const allMatchingSelected = totalItems > 0 && count >= totalItems;
  return pageCount > 1 && count > 0 && !allMatchingSelected ? totalItems : undefined;
}
