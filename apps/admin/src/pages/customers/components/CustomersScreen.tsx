import { useEffect, useMemo, useState } from 'react';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Card, CardContent } from '@/components/ui/card';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '../customers-page.filter-rows';
import { countActiveFilters, patchForSuggestion, type FilterDropSuggestion } from '../customers-page.filter-suggestions';
import type { MockCustomer } from '../customers-page.mock-data';
import {
  computeHeaderSelection,
  computeSelectAllCount,
  EMPTY_SELECTION,
  isRowSelected,
  selectionCount,
  toggleAllOnPage,
  toggleRowSelection,
  type SelectionState,
} from '../customers-page.selection';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';
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
 * Owns row selection — the one VIEW-only concern left. Page, filters AND
 * sort are all DATA concerns owned by `CustomersPage.tsx`: the requested
 * page number, search/filter values and sort column/direction are all query
 * params sent to the server, so each change fetches exactly the matching,
 * correctly-ordered page instead of paginating/narrowing/reordering an
 * already-fetched full list.
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
  totalItems,
  suggestions,
  filters,
  onFiltersChange,
  isRebuilding,
  page,
  pageCount,
  onPageChange,
  sortBy,
  sortDir,
  onSort,
}: {
  /** The current page's rows, already search/filter-narrowed AND sorted server-side — see CustomersPage.tsx. */
  rows: MockCustomer[];
  /** `useCustomersDemoQuery` still in flight (including a page/sort change — each is its own fetch). */
  isLoading: boolean;
  /** The whole store has zero rows, from the server's total — not "this page's filters matched nothing". */
  isStoreEmpty: boolean;
  /** The server's row count for the current filters, across every page — see CustomersPage.tsx. */
  totalItems: number;
  /** "Which filter to drop" suggestions — fetched by CustomersPage.tsx once filters match zero rows. */
  suggestions: FilterDropSuggestion[];
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  /** "Simulate rebuild" toggle (Pro-only, see CustomersPage.tsx) — no real background job. */
  isRebuilding: boolean;
  page: number;
  pageCount: number;
  onPageChange: (page: number) => void;
  sortBy: SortKey;
  sortDir: SortDirection;
  onSort: (key: SortKey) => void;
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

  // Selection — not scoped to a page: navigating pages keeps prior picks.
  // 'all' mode ("every row matching these filters") is an O(1) flag, never a
  // fetch of every matching id — see customers-page.selection.ts.
  const [selection, setSelection] = useState<SelectionState>(EMPTY_SELECTION);

  // The filters changed — the matching set changed, so past picks may no
  // longer apply. Page reset lives in CustomersPage.tsx now: it owns page,
  // since going back to page 1 on a filter change means re-fetching it.
  useEffect(() => {
    setSelection(EMPTY_SELECTION);
  }, [filters]);

  const headerSelection = useMemo(() => computeHeaderSelection(rows, selection), [rows, selection]);

  // The table only ever needs to know THIS page's checked state — bounded by
  // page size regardless of how large `totalItems` gets, even in 'all' mode.
  const selectedOnPage = useMemo(
    () => new Set(rows.filter((row) => isRowSelected(selection, row.user_id)).map((row) => row.user_id)),
    [rows, selection],
  );

  function toggleRow(userId: number) {
    setSelection((current) => toggleRowSelection(current, userId));
  }

  function toggleAll() {
    setSelection((current) => toggleAllOnPage(current, rows));
  }

  // Second bulk-bar tier: as soon as anything is picked and there's more
  // data beyond this page, offer expanding to every row matching the filters.
  const selectAllCount = computeSelectAllCount(selection, totalItems, pageCount);

  function selectAllMatching() {
    setSelection({ mode: 'all', excludedIds: new Set() });
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
          rows={rows}
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
          selected={selectedOnPage}
          onToggleRow={toggleRow}
          sortBy={sortBy}
          sortDir={sortDir}
          onSort={onSort}
        />

        {/* One bordered footer row: bulk actions (left, once selected) + pagination
            (right) — matches YayUiKits' TablePage.tsx composition of
            BulkActionBox + Pagination side by side. */}
        <div className="flex flex-col items-center gap-3 sm:flex-row">
          {showSelection && (
            <CustomersBulkActionBar
              count={selectionCount(selection, totalItems)}
              onClear={() => setSelection(EMPTY_SELECTION)}
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
