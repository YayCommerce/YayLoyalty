import { useRef, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { sprintf, __ } from '@wordpress/i18n';
import { useDebounceFn } from 'rooks';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CustomersScreen } from './components/CustomersScreen';
import { DEFAULT_ROW_FILTERS, type RowFilters } from './customers-page.filter-rows';
import { countActiveFilters, toFilterDropSuggestions } from './customers-page.filter-suggestions';
import { useCustomersDemoQuery } from './customers-page.queries';
import { defaultSortDirection, type SortDirection, type SortKey } from './customers-page.sort-rows';

const PER_PAGE = 10;

/**
 * Customers list screen — designed UI, driven by mock data fetched over a
 * real REST round trip until the real list endpoint lands (see
 * `customer.queries.ts` — only single-customer / ledger / adjust hooks exist
 * today; `customers-page.queries.ts` is the TEMPORARY stand-in).
 *
 * Owns the DATA concerns: the list query (one page at a time — `page` here
 * is what triggers each fetch, not a client-side slice of an already-loaded
 * list), the filters and sort (both sent to the server so they check/order
 * the whole demo dataset, not just whatever page loaded — see
 * `customers-page.api.ts`), and the "Simulate rebuild" toggle (no real
 * background job — see CustomersRebuildBanner.tsx). The "which filter to
 * drop" suggestions ride along inside the same list response once it's
 * empty — no second query. Row selection is the one VIEW-only concern left
 * in `CustomersScreen.tsx`.
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
  const [sortBy, setSortBy] = useState<SortKey>('balance');
  const [sortDir, setSortDir] = useState<SortDirection>('desc');

  // rooks' `useDebounceFn` has no `.cancel()` — a generation counter does the
  // same job: a debounced commit only applies if nothing else (Clear all, a
  // suggestion, another filter, or the search box being cleared back to '')
  // has happened since it was scheduled. Without this, typing → Clear all
  // inside the 300ms window let the stale search win once the timer fired.
  const searchGeneration = useRef(0);

  const [commitSearch] = useDebounceFn(
    (search: string, generation: number) => {
      if (generation !== searchGeneration.current) return;
      setQueryFilters((current) => ({ ...current, search }));
    },
    300,
    { trailing: true, maxWait: 800 },
  );

  const customersQuery = useCustomersDemoQuery({ page, per_page: PER_PAGE, orderby: sortBy, order: sortDir, ...queryFilters });
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

  // Raw `{key, count}` pairs the server already folded into this same
  // response (see `customers-page.api.ts`) — only non-empty once the current
  // filters match zero rows. Turned into display text from `queryFilters`,
  // the filter state that actually produced this response.
  const suggestions = toFilterDropSuggestions(customersQuery.data?.suggestions ?? [], queryFilters);

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
      // Trimmed BEFORE it reaches `queryFilters` — that object feeds the
      // query key (see `useCustomersDemoQuery` below), so "abc" and "abc "
      // must produce the exact same key or every trailing space typed (and
      // then backspaced) re-fetches instead of reusing the cached "abc"
      // result. Leading/trailing spaces alone (or none at all) also count as
      // cleared and apply immediately rather than waiting out the debounce.
      const trimmed = patch.search.trim();

      if (trimmed !== '') {
        commitSearch(trimmed, ++searchGeneration.current);
        return;
      }
    }

    // Bump the generation first so any debounced keystroke commit still in
    // flight sees a mismatch and no-ops instead of firing later and
    // clobbering this immediate change (see the comment by `commitSearch`).
    searchGeneration.current++;
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
          <Skeleton className="h-4 w-32" />
        ) : customersQuery.isError ? null : (
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
      {customersQuery.isError ? (
        // No table underneath: an empty store/no-match state implies a real,
        // successful 0-row answer from the server — showing it next to "could
        // not load" claims the request both failed and told us something.
        <ErrorState message={__('Could not load customers.', 'yayloyalty')} onRetry={() => customersQuery.refetch()} />
      ) : (
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
          sortBy={sortBy}
          sortDir={sortDir}
          onSort={handleSort}
        />
      )}
    </PageLayout>
  );
}
