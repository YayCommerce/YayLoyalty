import { getCustomers } from '@/lib/api/customer.api';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import type { RowFilters } from './customers-page.filter-rows';
import { isRowSelected, type SelectionState } from './customers-page.selection';
import type { SortDirection, SortKey } from './customers-page.sort-rows';
import { resolveBalanceRangeForQuery } from './customers-page.tier-sample-data';

/** The server's per_page cap (AbstractRepository::MAX_PER_PAGE), so the walk takes the fewest requests. */
const MAX_PER_PAGE = 100;

/** Ceiling on pages one bulk action walks (50,000 rows); beyond that, fail fast rather than hang the tab. */
const MAX_PAGES = 500;

/**
 * Every row matching the current filters, for bulk actions. Sequential, not
 * parallel: it runs from a click, and N concurrent list requests are worse
 * for the server. `signal` lets a large walk be cancelled on unmount.
 */
export async function fetchAllMatchingCustomers(
  filters: RowFilters,
  sort: { by: SortKey; dir: SortDirection },
  signal?: AbortSignal,
): Promise<CustomerListRow[]> {
  const params = {
    search: filters.search,
    // Same tier → balance_range substitution as the list query, or a Tier pick matches nothing.
    balanceRange: resolveBalanceRangeForQuery(filters),
    expiringSoon: filters.expiringSoon,
    tier: 'all' as const,
    orderBy: sort.by,
    order: sort.dir,
    perPage: MAX_PER_PAGE,
  };

  const first = await getCustomers({ ...params, page: 1 }, signal);

  if (first.totalPage > MAX_PAGES) {
    throw new Error(
      `This selection matches too many customers to process in the browser (over ${MAX_PAGES * MAX_PER_PAGE}). Narrow the filters and try again.`,
    );
  }

  const rows = [...first.data];

  for (let page = 2; page <= first.totalPage; page++) {
    const next = await getCustomers({ ...params, page }, signal);
    rows.push(...next.data);
  }

  return rows;
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
