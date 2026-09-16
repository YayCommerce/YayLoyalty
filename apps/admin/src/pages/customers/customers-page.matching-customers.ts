import { getCustomers } from '@/lib/api/customer.api';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import type { RowFilters } from './customers-page.filter-rows';
import { isRowSelected, type SelectionState } from './customers-page.selection';
import type { SortDirection, SortKey } from './customers-page.sort-rows';
import { resolveBalanceRangeForQuery } from './customers-page.tier-sample-data';

/**
 * Server cap on `per_page` — see `AbstractRepository::MAX_PER_PAGE`. Used as
 * the page size here too: the largest page the server will hand back in one
 * round trip, so fetching "every matching row" takes the fewest requests.
 */
const MAX_PER_PAGE = 100;

/**
 * Hard ceiling on how many pages a single bulk action will walk — 500 × 100
 * rows = 50,000 customers. `totalPage` is server-reported and unbounded, so
 * without this a store that size (or a too-broad filter) would fire 500+
 * sequential requests from one click and leave the tab hanging for minutes
 * with no way to cancel. Real stores this large need a narrower filter (or a
 * real export job) rather than this client-side loop; better to fail fast
 * with a clear reason than to hang.
 */
const MAX_PAGES = 500;

/**
 * Every row matching the current search/filter/sort — not just the one page
 * `CustomersPage.tsx` has loaded. Used by bulk actions (Export, Adjust
 * points), which both need to resolve the whole match set: `page` is a view
 * of the results, not the results themselves, and selection ("all N matching
 * these filters") is deliberately never held as a full id list client-side
 * (see `customers-page.selection.ts`) until an action actually needs one.
 *
 * Sequential, not parallel: this runs from an explicit user action (a click),
 * not a render, so there's no latency budget to protect — and hitting the
 * list endpoint with N concurrent requests for a store with thousands of
 * customers is worse for the server than N sequential ones.
 *
 * `signal` is optional — callers pass one so a large multi-page walk can be
 * cancelled (e.g. the triggering component unmounts) instead of running to
 * completion in the background for no reason. Passed straight through to
 * every `getCustomers()` call; an already-aborted signal makes the very next
 * one reject immediately rather than firing.
 */
export async function fetchAllMatchingCustomers(
  filters: RowFilters,
  sort: { by: SortKey; dir: SortDirection },
  signal?: AbortSignal,
): Promise<CustomerListRow[]> {
  const params = {
    search: filters.search,
    // Same Tier → Balance range substitution `CustomersPage.tsx` sends for
    // the main list query — a Tier pick as the raw `tier` param matches zero
    // rows server-side, so Export/bulk-Adjust would silently see nothing to
    // act on without this. See `customers-page.tier-sample-data.ts`.
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

/** Narrows a matching-set fetch down to only the rows the user actually picked. */
export function resolveSelectedCustomers(rows: CustomerListRow[], selection: SelectionState): CustomerListRow[] {
  return rows.filter((row) => isRowSelected(selection, row.user_id));
}

/**
 * True when every selected row is already sitting in `loadedRows` — the
 * common case (a handful of rows checked on the page currently on screen,
 * no navigation since) where a bulk action can skip `fetchAllMatchingCustomers`
 * entirely and resolve straight from what's already in hand.
 *
 * 'all' mode is never "fully loaded": it means every row matching the
 * filters except an exclusion set, which by construction can include rows
 * client-side code has never fetched (see `customers-page.selection.ts`) — so
 * this always reports `false` for it and callers always re-fetch instead.
 */
export function isSelectionFullyLoaded(loadedRows: CustomerListRow[], selection: SelectionState): boolean {
  if (selection.mode !== 'partial') return false;

  const loadedIds = new Set(loadedRows.map((row) => row.user_id));
  return [...selection.ids].every((id) => loadedIds.has(id));
}
