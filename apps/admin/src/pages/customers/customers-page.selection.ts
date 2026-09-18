import { useEffect, useMemo, useState } from 'react';

import type { CustomerListRow } from '@/lib/schema/customer.schema';
import type { RowFilters } from './customers-page.filter-rows';

/**
 * Row-selection math for CustomersScreen. Selection is either picked ids
 * ('partial') or every matching row except these ('all'), so "select all N
 * matching" never fetches or holds every id.
 */
export type SelectionState = { mode: 'partial'; ids: Set<number> } | { mode: 'all'; excludedIds: Set<number> };

export const EMPTY_SELECTION: SelectionState = { mode: 'partial', ids: new Set() };

export function isRowSelected(state: SelectionState, userId: number): boolean {
  return state.mode === 'all' ? !state.excludedIds.has(userId) : state.ids.has(userId);
}

/** Rows selected; `totalItems` is the server's count for the current filters. */
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

/** Header checkbox: toggles this page's rows only; other pages keep their picks. */
export function toggleAllOnPage(state: SelectionState, pageRows: CustomerListRow[]): SelectionState {
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
export function computeHeaderSelection(pageRows: CustomerListRow[], state: SelectionState): HeaderSelectionState {
  if (pageRows.length === 0) return 'none';
  const selectedOnPage = pageRows.filter((row) => isRowSelected(state, row.user_id)).length;
  if (selectedOnPage === 0) return 'none';
  if (selectedOnPage === pageRows.length) return 'all';
  return 'partial';
}

/**
 * Count for the "Select all N matching these filters" link; undefined when
 * there is nothing to expand into. Offered as soon as any row is picked.
 */
export function computeSelectAllCount(
  state: SelectionState,
  totalItems: number,
  pageCount: number,
): number | undefined {
  const count = selectionCount(state, totalItems);
  const allMatchingSelected = totalItems > 0 && count >= totalItems;
  return pageCount > 1 && count > 0 && !allMatchingSelected ? totalItems : undefined;
}

/** The selection state and derived values CustomersScreen renders. */
export function useCustomerRowSelection({
  rows,
  queryFilters,
  totalItems,
  pageCount,
}: {
  rows: CustomerListRow[];
  /** Committed filters, not the per-keystroke value, so typing does not reset the selection. */
  queryFilters: RowFilters;
  totalItems: number;
  pageCount: number;
}) {
  const [selection, setSelection] = useState<SelectionState>(EMPTY_SELECTION);

  // The matching set changed, so past picks may no longer apply.
  useEffect(() => {
    setSelection(EMPTY_SELECTION);
  }, [queryFilters]);

  const headerSelection = useMemo(() => computeHeaderSelection(rows, selection), [rows, selection]);

  const selectedOnPage = useMemo(
    () => new Set(rows.filter((row) => isRowSelected(selection, row.user_id)).map((row) => row.user_id)),
    [rows, selection],
  );

  const selectAllCount = computeSelectAllCount(selection, totalItems, pageCount);

  return {
    selection,
    setSelection,
    headerSelection,
    selectedOnPage,
    selectAllCount,
    toggleRow: (userId: number) => setSelection((current) => toggleRowSelection(current, userId)),
    toggleAll: () => setSelection((current) => toggleAllOnPage(current, rows)),
    selectAllMatching: () => setSelection({ mode: 'all', excludedIds: new Set() }),
    clearSelection: () => setSelection(EMPTY_SELECTION),
    count: selectionCount(selection, totalItems),
  };
}
