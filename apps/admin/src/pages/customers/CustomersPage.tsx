import { useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { Outlet, useSearchParams } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useCustomersQuery } from '@/lib/queries/customer.queries';
import { useDebouncedSearchCommit } from '@/hooks/useDebouncedSearchCommit';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import { CustomersScreen } from './components/CustomersScreen';
import { initialFiltersFromSearch, TIER_FILTER_ENABLED, type RowFilters } from './customers-page.filter-rows';
import { countActiveFilters, toFilterDropSuggestions } from './customers-page.filter-suggestions';
import { defaultSortDirection, type SortDirection, type SortKey } from './customers-page.sort-rows';
import { toServerFilters } from './customers-page.tier-sample-data';

const PER_PAGE = 10;

/**
 * Customers list screen. Owns the data concerns: the paged list query,
 * server-side filters and sort, and the demo "Simulate rebuild" toggle.
 * Row selection is the one view concern, left in CustomersScreen. Pro adds
 * columns and bulk actions on top of the same list via IS_PRO_BUILD.
 */
export default function CustomersPage() {
  // `filters` drives the toolbar instantly; `queryFilters` drives the fetch, with search debounced.
  // Seeded from `?balance_range=` once on mount; same-route navigation will not re-seed.
  const [searchParams] = useSearchParams();
  const [filters, setFilters] = useState<RowFilters>(() => initialFiltersFromSearch(searchParams));
  const [queryFilters, setQueryFilters] = useState<RowFilters>(filters);
  const [isRebuilding, setIsRebuilding] = useState(false);
  const [page, setPage] = useState(1);
  const [sortBy, setSortBy] = useState<SortKey>('balance');
  const [sortDir, setSortDir] = useState<SortDirection>('desc');

  // See `useDebouncedSearchCommit`'s own docblock for why a generation
  // counter guards this and why `cancelPending()` exists alongside `commit()`.
  const { commit: commitSearch, cancelPending: cancelPendingSearch } = useDebouncedSearchCommit((search) =>
    setQueryFilters((current) => ({ ...current, search })),
  );

  const customersQuery = useCustomersQuery({
    page,
    perPage: PER_PAGE,
    orderBy: sortBy,
    order: sortDir,
    ...toServerFilters(queryFilters),
  });
  const rows = customersQuery.data?.data ?? [];
  const totalItems = customersQuery.data?.totalItems ?? 0;
  const serverPageCount = customersQuery.data?.totalPage;
  // Zero matches gives totalPage 0; clamp so the footer reads "1 of 1".
  const pageCount = Math.max(1, serverPageCount ?? 1);

  // A sort change can leave `page` past the new last page; placeholderData would show stale rows silently.
  useEffect(() => {
    if (serverPageCount !== undefined && page > serverPageCount) {
      setPage(1);
    }
  }, [serverPageCount, page]);

  // Empty store vs. filters matching nobody: the server only returns the post-filter count.
  const activeFilterCount = countActiveFilters(queryFilters, IS_PRO_BUILD && TIER_FILTER_ENABLED);
  const isStoreEmpty = !customersQuery.isPending && totalItems === 0 && activeFilterCount === 0;

  // Labelled from `queryFilters`, the state that actually produced this response.
  const suggestions = toFilterDropSuggestions(customersQuery.data?.suggestions ?? [], queryFilters);

  function updateFilters(patch: Partial<RowFilters>) {
    setFilters((current) => ({ ...current, ...patch }));
    setPage(1);

    // Only a raw keystroke from the search input ever sends a patch that is
    // exactly `{ search }` (see CustomersToolbar.tsx). Every other change —
    // a select (a single non-search key), a suggestion dropping one filter
    // (also single-key, see `patchForSuggestion`'s 'without' case), or a
    // "clear all"/search-suggestion patch (every field at once) — fails that
    // shape check and is a deliberate one-off action applied immediately,
    // not debounced. `commitSearch` itself trims and applies immediately
    // when the trimmed value is empty (see the hook's own docblock).
    if (Object.keys(patch).length === 1 && patch.search !== undefined) {
      commitSearch(patch.search);
      return;
    }

    // Cancel any debounced keystroke commit still in flight so it can't fire
    // later and clobber this immediate change (see `cancelPending`'s docblock).
    cancelPendingSearch();
    setQueryFilters((current) => ({
      ...current,
      ...patch,
      ...(patch.search !== undefined ? { search: patch.search.trim() } : null),
    }));
  }

  function handleSort(key: SortKey) {
    if (key === sortBy) {
      setSortDir((current) => (current === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortBy(key);
      setSortDir(defaultSortDirection(key));
    }
  }

  return (
    <PageLayout
      title={__('Customers', 'yayloyalty')}
      description={
        customersQuery.isPending ? (
          <Skeleton tone="page" className="h-4 w-32" />
        ) : customersQuery.isError ? null : (
          sprintf(__('%d program members', 'yayloyalty'), totalItems)
        )
      }
      actions={
        IS_PRO_BUILD && (
          // Demo-only toggle; there is no real background job. See CustomersRebuildBanner.
          <Button variant="outline" size="sm" onClick={() => setIsRebuilding((current) => !current)}>
            <RefreshCw className="size-3.5" />
            {isRebuilding ? __('Stop simulating rebuild', 'yayloyalty') : __('Simulate rebuild', 'yayloyalty')}
          </Button>
        )
      }
    >
      {customersQuery.isError ? (
        // No empty state underneath: that would claim the request both failed and answered.
        <ErrorState message={__('Could not load customers.', 'yayloyalty')} onRetry={() => customersQuery.refetch()} />
      ) : (
        <CustomersScreen
          list={{
            rows,
            isLoading: customersQuery.isPending,
            isFetching: customersQuery.isFetching,
            isStoreEmpty,
            totalItems,
            suggestions,
          }}
          filters={{ value: filters, onChange: updateFilters }}
          // The committed filters, so typing does not wipe a selection before the query is sent.
          queryFilters={queryFilters}
          sort={{ by: sortBy, dir: sortDir, onChange: handleSort }}
          pagination={{ page, pageCount, onChange: setPage }}
          isRebuilding={isRebuilding}
        />
      )}
      {/* The drawer route (`customers/:userId`) renders here — the list above
          stays mounted and keeps its filters/page/sort/selection regardless
          of whether the list itself errored. */}
      <Outlet />
    </PageLayout>
  );
}
