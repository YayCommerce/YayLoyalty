import { useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { sprintf, __ } from '@wordpress/i18n';
import { useDebounceFn } from 'rooks';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CustomersScreen } from './components/CustomersScreen';
import { DEFAULT_ROW_FILTERS, type RowFilters } from './customers-page.filter-rows';
import { countActiveFilters } from './customers-page.filter-suggestions';
import { useCustomersDemoQuery, useCustomersDemoSuggestionsQuery } from './customers-page.queries';

const PER_PAGE = 10;

/**
 * Customers list screen — designed UI, driven by mock data fetched over a
 * real REST round trip until the real list endpoint lands (see
 * `customer.queries.ts` — only single-customer / ledger / adjust hooks exist
 * today; `customers-page.queries.ts` is the TEMPORARY stand-in).
 *
 * Owns the DATA concerns: the list query (one page at a time — `page` here
 * is what triggers each fetch, not a client-side slice of an already-loaded
 * list), the filters (sent to the server so search/filter check the whole
 * demo dataset, not just whatever page loaded — see `customers-page.api.ts`),
 * the "which filter to drop" suggestions query, and the "Simulate rebuild"
 * toggle (no real background job — see CustomersRebuildBanner.tsx). Sort and
 * row selection stay VIEW-only state in `CustomersScreen.tsx`.
 *
 * FREE/PRO SPLIT IS `IS_PRO_BUILD`, DIRECTLY — no demo switcher, no upgrade
 * overlay. Free gets a fully working list (search, sort, filter, export,
 * pagination); Pro adds the Tier/Referrals columns, row selection and the
 * bulk action bar on top of the same list — see `CustomersScreen.tsx`.
 */
export default function CustomersPage() {
  // `filters` drives the toolbar UI (instant, so typing never feels laggy).
  // `queryFilters` drives the actual fetch — same value, except the search
  // text is committed after a short pause instead of on every keystroke, so
  // typing a name doesn't fire one API request per character.
  const [filters, setFilters] = useState<RowFilters>(DEFAULT_ROW_FILTERS);
  const [queryFilters, setQueryFilters] = useState<RowFilters>(DEFAULT_ROW_FILTERS);
  const [isRebuilding, setIsRebuilding] = useState(false);
  const [page, setPage] = useState(1);

  const [commitSearch] = useDebounceFn(
    (search: string) => setQueryFilters((current) => ({ ...current, search })),
    300,
    { trailing: true, maxWait: 800 },
  );

  const customersQuery = useCustomersDemoQuery({ page, per_page: PER_PAGE, ...queryFilters });
  const rows = customersQuery.data?.data ?? [];
  const totalItems = customersQuery.data?.totalItems ?? 0;
  // Zero matches means the server's own totalPage is 0 (ceil(0 / perPage)) —
  // clamp to 1 so the pagination footer reads "1 of 1", not "1 of 0".
  const pageCount = Math.max(1, customersQuery.data?.totalPage ?? 1);

  // Zero rows means one of two very different things: a genuinely empty
  // store, or these filters matching nobody — the server can't tell them
  // apart (it only ever returns the post-filter count), so that split still
  // happens here from the filters the page itself is holding.
  const activeFilterCount = countActiveFilters(queryFilters, IS_PRO_BUILD);
  const isStoreEmpty = !customersQuery.isPending && totalItems === 0 && activeFilterCount === 0;
  const isNoMatch = !customersQuery.isPending && totalItems === 0 && activeFilterCount > 0;

  const suggestionsQuery = useCustomersDemoSuggestionsQuery(queryFilters, IS_PRO_BUILD, isNoMatch);
  const suggestions = suggestionsQuery.data ?? [];

  function updateFilters(patch: Partial<RowFilters>) {
    setFilters((current) => ({ ...current, ...patch }));
    // The matching set changed — a stale page number (e.g. page 3 of a filter
    // that now fits on one) would fetch a page that doesn't apply anymore.
    setPage(1);

    // Only a raw keystroke from the search input ever sends a lone `search`
    // key (see CustomersToolbar.tsx) — every other change (a select, or a
    // suggestion/"clear all" patch, which always carries every field) is a
    // deliberate one-off action and applies immediately, not debounced.
    if (Object.keys(patch).length === 1 && patch.search !== undefined) {
      commitSearch(patch.search);
    } else {
      setQueryFilters((current) => ({ ...current, ...patch }));
    }
  }

  return (
    <PageLayout
      title={__('Customers', 'yayloyalty')}
      description={
        customersQuery.isPending ? (
          <Skeleton className="h-4 w-32" />
        ) : (
          sprintf(__('%d program members', 'yayloyalty'), totalItems)
        )
      }
      actions={
        IS_PRO_BUILD && (
          // Demo-only: there is no real background job to reflect, so this is
          // a plain toggle — not a "Tools" trigger. See CustomersRebuildBanner.tsx.
          <Button variant="outline" size="sm" onClick={() => setIsRebuilding((current) => !current)}>
            <RefreshCw className="size-3.5" />
            {isRebuilding ? __('Stop simulating rebuild', 'yayloyalty') : __('Simulate rebuild', 'yayloyalty')}
          </Button>
        )
      }
    >
      {customersQuery.isError && (
        <p className="text-destructive text-sm">{__('Could not load customers.', 'yayloyalty')}</p>
      )}

      <CustomersScreen
        rows={rows}
        isLoading={customersQuery.isPending}
        isStoreEmpty={isStoreEmpty}
        totalItems={totalItems}
        suggestions={suggestions}
        filters={filters}
        onFiltersChange={updateFilters}
        isRebuilding={isRebuilding}
        page={page}
        pageCount={pageCount}
        onPageChange={setPage}
      />
    </PageLayout>
  );
}
