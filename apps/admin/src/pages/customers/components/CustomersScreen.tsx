import { useEffect, useMemo, useState } from 'react';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Card, CardContent } from '@/components/ui/card';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '../customers-page.filter-rows';
import { countActiveFilters, getFilterDropSuggestions, patchForSuggestion } from '../customers-page.filter-suggestions';
import type { MockCustomer } from '../customers-page.mock-data';
import { computeHeaderSelection, computeSelectAllCount } from '../customers-page.selection';
import { defaultSortDirection, sortRows, type SortDirection, type SortKey } from '../customers-page.sort-rows';
import { CustomersBulkActionBar } from './CustomersBulkActionBar';
import { CustomersFilterBar } from './CustomersFilterBar';
import { CustomersNoMatchState } from './CustomersNoMatchState';
import { CustomersPagination } from './CustomersPagination';
import { CustomersRebuildBanner } from './CustomersRebuildBanner';
import { CustomersTable } from './CustomersTable';

const PER_PAGE = 10;

/**
 * The Customers screen body: [saved segment row] + Export CSV → toolbar →
 * table → one footer row: [bulk action bar] (left, once rows are selected)
 * + pagination (right) — see the combined `<div>` near the bottom, matching
 * YayUiKits' `BulkActionBox` + `Pagination` layout.
 *
 * Owns every VIEW-only concern for the currently-loaded rows — sort, page
 * and row selection — since all three interact (sorting reorders before
 * paginating; "select all" only ever means "this page"; changing the filtered
 * row set resets both page and selection). `CustomersPage.tsx` owns only the
 * DATA concerns: the query and the filters that shape `rows`.
 *
 * The bracketed Pro pieces are gated directly by `IS_PRO_BUILD` — no demo
 * switcher, no upgrade overlay for a feature that simply adds columns rather
 * than walling off the page (same split `CampaignsPage.tsx` uses, and the
 * `useIsPremium`-style check yay-reviews' `PremiumGate` is built on).
 */
export function CustomersScreen({
  allRows,
  rows,
  isLoading,
  filters,
  onFiltersChange,
  isRebuilding,
}: {
  /** Unfiltered rows, for the pagination "{range} of {total}" total and the empty-store check. */
  allRows: MockCustomer[];
  /** Already search/filter-narrowed by the page — see CustomersPage.tsx. */
  rows: MockCustomer[];
  /** `useCustomersDemoQuery` still in flight. */
  isLoading: boolean;
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  /** "Simulate rebuild" toggle (Pro-only, see CustomersPage.tsx) — no real background job. */
  isRebuilding: boolean;
}) {
  // A genuinely empty store, not a search/filter matching nobody, and not
  // "still loading" either — see CustomersTable.tsx / CustomersEmptyState.tsx
  // for why the first distinction matters. The toolbar goes inert (but stays
  // in place) once there's nothing to search, filter or export; while
  // loading it stays live per the design — only the table body skeletons.
  const isStoreEmpty = !isLoading && allRows.length === 0;
  const isNoMatch = !isLoading && !isStoreEmpty && rows.length === 0;

  // "Simulate rebuild": every control goes inert and no row can be selected
  // (see CustomerTableRow.tsx / CustomersRebuildBanner.tsx) while it's on.
  const controlsDisabled = isStoreEmpty || isRebuilding;
  const showSelection = IS_PRO_BUILD && !isRebuilding;

  // Real counts, not canned copy — see customers-page.filter-suggestions.ts.
  const suggestions = useMemo(
    () => (isNoMatch ? getFilterDropSuggestions(allRows, filters, IS_PRO_BUILD) : []),
    [isNoMatch, allRows, filters],
  );

  // Sort — real, over the whole filtered set, applied before paging so a
  // sort click reorders across every page, not just the one on screen.
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

  // Real pagination — 10 rows/page over the sorted, filtered set.
  const [page, setPage] = useState(1);
  const pageCount = Math.max(1, Math.ceil(sortedRows.length / PER_PAGE));
  const safePage = Math.min(page, pageCount);
  const pageRows = sortedRows.slice((safePage - 1) * PER_PAGE, safePage * PER_PAGE);

  // Selection — a Set<user_id>, not scoped to a page: navigating pages keeps
  // prior picks, but the header checkbox and "select all" only ever act on
  // the current page (standard admin-table convention once paging is real).
  const [selected, setSelected] = useState<Set<number>>(new Set());

  // The filtered row set changed (a filter changed) — start over.
  useEffect(() => {
    setPage(1);
    setSelected(new Set());
  }, [rows]);

  const headerSelection = useMemo(() => computeHeaderSelection(pageRows, selected), [pageRows, selected]);

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
      const allOnPageSelected = pageRows.every((row) => current.has(row.user_id));
      const next = new Set(current);
      pageRows.forEach((row) => (allOnPageSelected ? next.delete(row.user_id) : next.add(row.user_id)));
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
          rows={pageRows}
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
            page={safePage}
            pageCount={pageCount}
            isLoading={isLoading}
            onPrevious={() => setPage((current) => Math.max(1, current - 1))}
            onNext={() => setPage((current) => Math.min(pageCount, current + 1))}
            onPageChange={setPage}
            className="sm:ms-auto"
          />
        </div>
      </CardContent>
    </Card>
  );
}
