import { useEffect, useState } from 'react';
import { Outlet, useSearchParams } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { useCustomersQuery } from '@/lib/queries/customer.queries';
import { useDebouncedSearchCommit } from '@/hooks/useDebouncedSearchCommit';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import { CustomersScreen } from './components/CustomersScreen';
import { initialFiltersFromSearch, type RowFilters } from './customers-page.filter-rows';
import { countActiveFilters, toFilterDropSuggestions } from './customers-page.filter-suggestions';
import { defaultSortDirection, type SortDirection, type SortKey } from './customers-page.sort-rows';

const PER_PAGE = 10;

/**
 * Customers list screen. Owns the data concerns: the paged list query,
 * server-side filters and sort.
 * Row selection is the one view concern, left in CustomersScreen. Pro adds
 * columns and bulk actions on top of the same list via IS_PRO_BUILD.
 */
export default function CustomersPage() {
  // `filters` drives the toolbar instantly; `queryFilters` drives the fetch, with search debounced.
  // Seeded from `?balance_range=` once on mount; same-route navigation will not re-seed.
  const [searchParams] = useSearchParams();
  const [filters, setFilters] = useState<RowFilters>(() => initialFiltersFromSearch(searchParams));
  const [queryFilters, setQueryFilters] = useState<RowFilters>(filters);
  const [page, setPage] = useState(1);
  const [sortBy, setSortBy] = useState<SortKey>('balance');
  const [sortDir, setSortDir] = useState<SortDirection>('desc');

  const { commit: commitSearch, cancelPending: cancelPendingSearch } = useDebouncedSearchCommit((search) =>
    setQueryFilters((current) => ({ ...current, search })),
  );

  const customersQuery = useCustomersQuery({
    page,
    perPage: PER_PAGE,
    orderBy: sortBy,
    order: sortDir,
    ...queryFilters,
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
  const activeFilterCount = countActiveFilters(queryFilters);
  const isStoreEmpty = !customersQuery.isPending && totalItems === 0 && activeFilterCount === 0;

  // Labelled from `queryFilters`, the state that actually produced this response.
  const suggestions = toFilterDropSuggestions(customersQuery.data?.suggestions ?? [], queryFilters);

  function updateFilters(patch: Partial<RowFilters>) {
    setFilters((current) => ({ ...current, ...patch }));
    setPage(1);

    // Only a search keystroke sends a patch of exactly `{ search }`, and only that is debounced.
    // Every other patch is a deliberate action and applies at once.
    if (Object.keys(patch).length === 1 && patch.search !== undefined) {
      commitSearch(patch.search);
      return;
    }

    // A search commit still in flight would otherwise overwrite this change when it fires.
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
          sprintf(
            /* translators: %d: number of program members. */
            __('%d program members', 'yayloyalty'),
            totalItems,
          )
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
        />
      )}
      {/* The drawer route renders here, so the list stays mounted and keeps its state. */}
      <Outlet />
    </PageLayout>
  );
}
