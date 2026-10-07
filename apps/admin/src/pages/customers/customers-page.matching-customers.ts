import { getCustomers } from '@/lib/api/customer.api';
import { fetchAllPages, MAX_PAGES, MAX_PER_PAGE } from '@/lib/api/paginated';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { bulkTooLargeSelection } from './components/customers-adjust-points.bulk.copy';
import type { RowFilters } from './customers-page.filter-rows';
import { isRowSelected, type SelectionState } from './customers-page.selection';
import type { SortDirection, SortKey } from './customers-page.sort-rows';

/** The most rows one bulk action can touch (50,000) — the adjust dialog blocks a larger selection up front. */
export const MAX_MATCHING_ROWS = MAX_PAGES * MAX_PER_PAGE;

/** Every row matching the current filters, for bulk actions. `signal` lets a large walk be cancelled on unmount. */
export async function fetchAllMatchingCustomers(
  filters: RowFilters,
  sort: { by: SortKey; dir: SortDirection },
  signal?: AbortSignal,
): Promise<CustomerListRow[]> {
  const params = {
    ...filters,
    orderBy: sort.by,
    order: sort.dir,
    perPage: MAX_PER_PAGE,
  };

  return fetchAllPages((page, perPage) => getCustomers({ ...params, page, perPage }, signal), bulkTooLargeSelection);
}

/** Narrows a matching set to the rows the user picked. */
export function resolveSelectedCustomers(rows: CustomerListRow[], selection: SelectionState): CustomerListRow[] {
  return rows.filter((row) => isRowSelected(selection, row.user_id));
}

/**
 * Whether every selected row is already in `loadedRows`, so a bulk action
 * can skip the fetch. Never true for 'all' mode, which includes rows never fetched.
 */
export function isSelectionFullyLoaded(loadedRows: CustomerListRow[], selection: SelectionState): boolean {
  if (selection.mode !== 'partial') return false;

  const loadedIds = new Set(loadedRows.map((row) => row.user_id));
  return [...selection.ids].every((id) => loadedIds.has(id));
}
