import { useEffect, useMemo, useState } from 'react';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Card, CardContent } from '@/components/ui/card';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '../customers-page.filter-rows';
import { countActiveFilters, patchForSuggestion, type FilterDropSuggestion } from '../customers-page.filter-suggestions';
import type { MockCustomer } from '../customers-page.mock-data';
import { computeHeaderSelection, computeSelectAllCount } from '../customers-page.selection';
import { defaultSortDirection, sortRows, type SortDirection, type SortKey } from '../customers-page.sort-rows';
import { CustomersBulkActionBar } from './CustomersBulkActionBar';
import { CustomersFilterBar } from './CustomersFilterBar';
import { CustomersNoMatchState } from './CustomersNoMatchState';
import { CustomersPagination } from './CustomersPagination';
import { CustomersRebuildBanner } from './CustomersRebuildBanner';
import { CustomersTable } from './CustomersTable';

/**
 * The Customers screen body: [saved segment row] + Export CSV → toolbar →
 * table → one footer row: [bulk action bar] (left, once rows are selected)
 * + pagination (right) — see the combined `<div>` near the bottom, matching
 * YayUiKits' `BulkActionBox` + `Pagination` layout.
 *
 * Owns sort and row selection — VIEW-only concerns over whatever page is
 * currently loaded. Page and filters are both DATA concerns owned by
 * `CustomersPage.tsx`: the requested page number and the search/filter values
 * are all query params sent to the server, so each page/filter change fetches
 * exactly the matching page instead of paginating or narrowing an
 * already-fetched full list. Sort is the one exception left client-side: it
 * only reorders the rows on screen (the one page that's loaded), not the
 * whole filtered set — server-side sort isn't implemented for this demo
 * endpoint.
 *
 * The bracketed Pro pieces are gated directly by `IS_PRO_BUILD` — no demo
 * switcher, no upgrade overlay for a feature that simply adds columns rather
 * than walling off the page (same split `CampaignsPage.tsx` uses, and the
 * `useIsPremium`-style check yay-reviews' `PremiumGate` is built on).
 */
export function CustomersScreen({
  rows,
  isLoading,
  isStoreEmpty,
  suggestions,
  filters,
  onFiltersChange,
  isRebuilding,
  page,
  pageCount,
  onPageChange,
}: {
  /** The current page's rows, already search/filter-narrowed server-side — see CustomersPage.tsx. */
  rows: MockCustomer[];
  /** `useCustomersDemoQuery` still in flight (including a page change — each page is its own fetch). */
  isLoading: boolean;
  /** The whole store has zero rows, from the server's total — not "this page's filters matched nothing". */
  isStoreEmpty: boolean;
  /** "Which filter to drop" suggestions — fetched by CustomersPage.tsx once filters match zero rows. */
  suggestions: FilterDropSuggestion[];
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  /** "Simulate rebuild" toggle (Pro-only, see CustomersPage.tsx) — no real background job. */
  isRebuilding: boolean;
  page: number;
  pageCount: number;
  onPageChange: (page: number) => void;
}) {
  // A genuinely empty store, not a search/filter matching nobody, and not
  // "still loading" either — see CustomersTable.tsx / CustomersEmptyState.tsx
  // for why the first distinction matters. The toolbar goes inert (but stays
  // in place) once there's nothing to search, filter or export; while
  // loading it stays live per the design — only the table body skeletons.
  const isNoMatch = !isLoading && !isStoreEmpty && rows.length === 0;

  // "Simulate rebuild": every control goes inert and no row can be selected
  // (see CustomerTableRow.tsx / CustomersRebuildBanner.tsx) while it's on.
  const controlsDisabled = isStoreEmpty || isRebuilding;
  const showSelection = IS_PRO_BUILD && !isRebuilding;

  // Sort — over the currently-loaded page only (see the class doc above).
  const [sortBy, setSortBy] = useState<SortKey>('balance');
  const [sortDir, setSortDir] = useState<SortDirection>('desc');

  function handleSort(key: SortKey) {
    if (key === sortBy) {
      setSortDir((current) => (current === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortBy(key);
      setSortDir(defaultSortDirection(key));
    }
  }

  const sortedRows = useMemo(() => sortRows(rows, sortBy, sortDir), [rows, sortBy, sortDir]);

  // Selection — a Set<user_id>, not scoped to a page: navigating pages keeps
  // prior picks, but the header checkbox and "select all" only ever act on
  // the current page (standard admin-table convention once paging is real).
  const [selected, setSelected] = useState<Set<number>>(new Set());

  // The filters changed — the matching set changed, so past picks may no
  // longer apply. Page reset lives in CustomersPage.tsx now: it owns page,
  // since going back to page 1 on a filter change means re-fetching it.
  useEffect(() => {
    setSelected(new Set());
  }, [filters]);

  const headerSelection = useMemo(() => computeHeaderSelection(sortedRows, selected), [sortedRows, selected]);

  function toggleRow(userId: number) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(userId)) {
        next.delete(userId);
      } else {
        next.add(userId);
      }
      return next;
    });
  }

  function toggleAll() {
    setSelected((current) => {
      const allOnPageSelected = sortedRows.every((row) => current.has(row.user_id));
      const next = new Set(current);
      sortedRows.forEach((row) => (allOnPageSelected ? next.delete(row.user_id) : next.add(row.user_id)));
      return next;
    });
  }

  // Second bulk-bar tier: the header checkbox only ever selects the current
  // page, so once there's more than one page, offer expanding to every row
  // matching the filters — real count, real action, not the canned "11,100".
  const selectAllCount = computeSelectAllCount(sortedRows, selected, pageCount);

  function selectAllMatching() {
    setSelected(new Set(sortedRows.map((row) => row.user_id)));
  }

  return (
    <Card>
      <CardContent className="flex flex-col gap-4">
        {isRebuilding && <CustomersRebuildBanner />}

        <CustomersFilterBar
          disabled={controlsDisabled}
          exportDisabled={controlsDisabled || isLoading || rows.length === 0}
          filters={filters}
          onFiltersChange={onFiltersChange}
        />

        <CustomersTable
          rows={sortedRows}
          isLoading={isLoading}
          isStoreEmpty={isStoreEmpty}
          noMatchContent={
            isNoMatch ? (
              <CustomersNoMatchState
                activeCount={countActiveFilters(filters, IS_PRO_BUILD)}
                suggestions={suggestions}
                onUseSuggestion={(suggestion) => onFiltersChange(patchForSuggestion(suggestion, filters))}
                onClearAll={() => onFiltersChange(DEFAULT_ROW_FILTERS)}
              />
            ) : undefined
          }
          showProColumns={IS_PRO_BUILD}
          showSelection={showSelection}
          staleBalances={isRebuilding}
          headerSelection={headerSelection}
          onToggleAll={toggleAll}
          selected={selected}
          onToggleRow={toggleRow}
          sortBy={sortBy}
          sortDir={sortDir}
          onSort={handleSort}
        />

        {/* One bordered footer row: bulk actions (left, once selected) + pagination
            (right) — matches YayUiKits' TablePage.tsx composition of
            BulkActionBox + Pagination side by side. */}
        <div className="flex flex-col items-center gap-3 sm:flex-row">
          {showSelection && (
            <CustomersBulkActionBar
              count={selected.size}
              onClear={() => setSelected(new Set())}
              selectAllCount={selectAllCount}
              onSelectAllMatching={selectAllMatching}
            />
          )}
          <CustomersPagination
            page={page}
            pageCount={pageCount}
            isLoading={isLoading}
            onPrevious={() => onPageChange(Math.max(1, page - 1))}
            onNext={() => onPageChange(Math.min(pageCount, page + 1))}
            onPageChange={onPageChange}
            className="sm:ms-auto"
          />
        </div>
      </CardContent>
    </Card>
  );
}
