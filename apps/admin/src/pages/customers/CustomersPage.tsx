import { useEffect, useRef, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { useDebounceFn } from 'rooks';
import { __, sprintf } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useCustomersQuery } from '@/lib/queries/customer.queries';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import { CustomersScreen } from './components/CustomersScreen';
import { DEFAULT_ROW_FILTERS, TIER_FILTER_ENABLED, type RowFilters } from './customers-page.filter-rows';
import { countActiveFilters, toFilterDropSuggestions } from './customers-page.filter-suggestions';
import { defaultSortDirection, type SortDirection, type SortKey } from './customers-page.sort-rows';

const PER_PAGE = 10;

/**
 * Customers list screen — real data, real endpoint. `useCustomersQuery`
 * (`lib/queries/customer.queries.ts`) calls `GET /customers`, the same
 * REST resource `useCustomerQuery`/`useAdjustPointsMutation` use for a
 * single customer — this screen only ever fetches the list projection
 * (`CustomerListRow`, not `Customer`).
 *
 * Owns the DATA concerns: the list query (one page at a time — `page` here
 * is what triggers each fetch, not a client-side slice of an already-loaded
 * list), the filters and sort (both sent to the server so they check/order
 * the whole table, not just whatever page loaded — see
 * `CustomerRepository::paginate_list()`), and the "Simulate rebuild" toggle
 * (no real background job — see CustomersRebuildBanner.tsx). The "which
 * filter to drop" suggestions ride along inside the same list response once
 * it's empty — no second query. Row selection is the one VIEW-only concern
 * left in `CustomersScreen.tsx`.
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

  const customersQuery = useCustomersQuery({
    page,
    per_page: PER_PAGE,
    orderby: sortBy,
    order: sortDir,
    ...queryFilters,
  });
  const rows = customersQuery.data?.data ?? [];
  const totalItems = customersQuery.data?.totalItems ?? 0;
  const serverPageCount = customersQuery.data?.totalPage;
  // Zero matches means the server's own totalPage is 0 (ceil(0 / perPage)) —
  // clamp to 1 so the pagination footer reads "1 of 1", not "1 of 0".
  const pageCount = Math.max(1, serverPageCount ?? 1);

  // A sort change (the only thing besides a filter change that can shrink the
  // match set without going through `updateFilters`, which already resets
  // `page` itself) can leave `page` pointing past the new last page — e.g.
  // sorting while on page 3 of a 2-page result. Snap back to page 1 rather
  // than rendering whatever the server hands back for an out-of-range page
  // (with `placeholderData` keeping stale rows visible, that page could
  // otherwise sit empty or wrong with nothing telling the user why).
  useEffect(() => {
    if (serverPageCount !== undefined && page > serverPageCount) {
      setPage(1);
    }
  }, [serverPageCount, page]);

  // Zero rows means one of two very different things: a genuinely empty
  // store, or these filters matching nobody — the server can't tell them
  // apart (it only ever returns the post-filter count), so that split still
  // happens here from the filters the page itself is holding.
  const activeFilterCount = countActiveFilters(queryFilters, IS_PRO_BUILD && TIER_FILTER_ENABLED);
  const isStoreEmpty = !customersQuery.isPending && totalItems === 0 && activeFilterCount === 0;

  // Raw `{key, count}` pairs the server already folded into this same
  // response (see `lib/api/customer.api.ts`) — only non-empty once the current
  // filters match zero rows. Turned into display text from `queryFilters`,
  // the filter state that actually produced this response.
  const suggestions = toFilterDropSuggestions(customersQuery.data?.suggestions ?? [], queryFilters);

  function updateFilters(patch: Partial<RowFilters>) {
    setFilters((current) => ({ ...current, ...patch }));
    // The matching set changed — a stale page number (e.g. page 3 of a filter
    // that now fits on one) would fetch a page that doesn't apply anymore.
    setPage(1);

    // Only a raw keystroke from the search input ever sends a patch that is
    // exactly `{ search }` (see CustomersToolbar.tsx). Every other change —
    // a select (a single non-search key), a suggestion dropping one filter
    // (also single-key, see `patchForSuggestion`'s 'without' case), or a
    // "clear all"/search-suggestion patch (every field at once) — fails that
    // shape check and is a deliberate one-off action applied immediately,
    // not debounced.
    if (Object.keys(patch).length === 1 && patch.search !== undefined) {
      // Trimmed BEFORE it reaches `queryFilters` — that object feeds the
      // query key (see `useCustomersQuery` below), so "abc" and "abc "
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
          list={{
            rows,
            isLoading: customersQuery.isPending,
            isFetching: customersQuery.isFetching,
            isStoreEmpty,
            totalItems,
            suggestions,
          }}
          filters={{ value: filters, onChange: updateFilters }}
          // The committed, debounced filters — not `filters` above (which
          // changes on every keystroke). Selection-reset and bulk actions key
          // off this so typing a search term doesn't wipe a picked selection
          // before the query it's meant to react to has even been sent.
          queryFilters={queryFilters}
          sort={{ by: sortBy, dir: sortDir, onChange: handleSort }}
          pagination={{ page, pageCount, onChange: setPage }}
          isRebuilding={isRebuilding}
        />
      )}
    </PageLayout>
  );
}
