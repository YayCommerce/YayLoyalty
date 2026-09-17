import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { Card, CardContent } from '@/components/ui/card';
import { useCustomersBulkActions } from '../customers-page.bulk-actions';
import { DEFAULT_ROW_FILTERS, TIER_FILTER_ENABLED, type RowFilters } from '../customers-page.filter-rows';
import {
  countActiveFilters,
  patchForSuggestion,
  type FilterDropSuggestion,
} from '../customers-page.filter-suggestions';
import { useCustomerRowSelection } from '../customers-page.selection';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';
import { CustomersAdjustPointsDialog } from './CustomersAdjustPointsDialog';
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
  list,
  filters: filtersProp,
  queryFilters,
  sort,
  pagination,
  isRebuilding,
}: {
  /** Everything the server's list query produced for the current page — see CustomersPage.tsx. */
  list: {
    /** Already search/filter-narrowed AND sorted server-side. */
    rows: CustomerListRow[];
    /** `useCustomersQuery` still in flight AND no data at all yet — drives the full-table skeleton. */
    isLoading: boolean;
    /** `useCustomersQuery` has a request in flight, period — true for `isLoading` too, but also true for a
     *  background page/sort/filter refetch that `placeholderData` is keeping previous rows on screen for.
     *  Dims the table so that background work is visible without a spinner. */
    isFetching: boolean;
    /** The whole store has zero rows, from the server's total — not "this page's filters matched nothing". */
    isStoreEmpty: boolean;
    /** The server's row count for the current filters, across every page. */
    totalItems: number;
    /** "Which filter to drop" — folded into the same GET /customers response envelope when filters match zero rows; no second fetch. */
    suggestions: FilterDropSuggestion[];
  };
  filters: {
    value: RowFilters;
    onChange: (patch: Partial<RowFilters>) => void;
  };
  /**
   * The committed (debounced) filters — what the current `list` actually
   * reflects, unlike `filters.value` which updates on every keystroke. Bulk
   * actions (Export, Adjust points) and the selection-reset effect below key
   * off this, not `filters.value`.
   */
  queryFilters: RowFilters;
  sort: {
    by: SortKey;
    dir: SortDirection;
    onChange: (key: SortKey) => void;
  };
  pagination: {
    page: number;
    pageCount: number;
    onChange: (page: number) => void;
  };
  /** "Simulate rebuild" toggle (Pro-only, see CustomersPage.tsx) — no real background job. */
  isRebuilding: boolean;
}) {
  const { rows, isLoading, isFetching, isStoreEmpty, totalItems, suggestions } = list;
  const { value: filters, onChange: onFiltersChange } = filtersProp;
  const { by: sortBy, dir: sortDir, onChange: onSort } = sort;
  const { page, pageCount, onChange: onPageChange } = pagination;

  // A genuinely empty store, not a search/filter matching nobody, and not
  // "still loading" either — see CustomersTable.tsx / CustomersEmptyState.tsx
  // for why that distinction matters (different empty-state messaging).
  const isNoMatch = !isLoading && !isStoreEmpty && rows.length === 0;

  // Toolbar goes inert (but stays in place) once there's nothing to search,
  // filter or export — deliberately NOT while merely loading (isLoading
  // plays no part here), so the toolbar stays live and only the table body
  // shows skeletons. "Simulate rebuild" also disables it: every control goes
  // inert and no row can be selected (see CustomerTableRow.tsx /
  // CustomersRebuildBanner.tsx) while it's on.
  const controlsDisabled = isStoreEmpty || isRebuilding;
  const showSelection = IS_PRO_BUILD && !isRebuilding;

  // Selection — not scoped to a page: navigating pages keeps prior picks.
  // 'all' mode ("every row matching these filters") is an O(1) flag, never a
  // fetch of every matching id — see customers-page.selection.ts, which also
  // owns the reset-on-filter-change rule (keyed off `queryFilters`, the
  // committed/debounced value — not `filters.value`, which changes on every
  // keystroke and used to wipe a selection before the query it's reacting to
  // had even fired).
  const {
    selection,
    setSelection,
    headerSelection,
    selectedOnPage,
    selectAllCount,
    toggleRow,
    toggleAll,
    selectAllMatching,
    clearSelection,
    count: selectedCount,
  } = useCustomerRowSelection({ rows, queryFilters, totalItems, pageCount });

  const {
    isExporting: isBulkExporting,
    handleExport: handleBulkExport,
    isAdjustDialogOpen,
    setIsAdjustDialogOpen,
    isAdjustSubmitting,
    handleAdjustSubmit: handleBulkAdjustSubmit,
  } = useCustomersBulkActions({ rows, queryFilters, sort, selection, setSelection });

  return (
    <>
      <Card>
        <CardContent className="flex flex-col gap-4">
          {isRebuilding && <CustomersRebuildBanner />}

          <CustomersFilterBar
            disabled={controlsDisabled}
            exportDisabled={controlsDisabled || isLoading || rows.length === 0}
            filters={filters}
            onFiltersChange={onFiltersChange}
            queryFilters={queryFilters}
            sort={sort}
          />

          <CustomersTable
            rows={rows}
            isLoading={isLoading}
            isFetching={isFetching}
            isStoreEmpty={isStoreEmpty}
            noMatchContent={
              isNoMatch ? (
                <CustomersNoMatchState
                  activeCount={countActiveFilters(filters, IS_PRO_BUILD && TIER_FILTER_ENABLED)}
                  suggestions={suggestions}
                  onUseSuggestion={(suggestion) => onFiltersChange(patchForSuggestion(suggestion, filters))}
                  onClearAll={() => onFiltersChange(DEFAULT_ROW_FILTERS)}
                />
              ) : undefined
            }
            showProColumns={IS_PRO_BUILD}
            staleBalances={isRebuilding}
            sort={{ by: sortBy, dir: sortDir, onChange: onSort }}
            selection={{
              enabled: showSelection,
              header: headerSelection,
              selected: selectedOnPage,
              onToggle: toggleRow,
              onToggleAll: toggleAll,
            }}
          />

          {/* One bordered footer row: bulk actions (left, once selected) + pagination
            (right) — matches YayUiKits' TablePage.tsx composition of
            BulkActionBox + Pagination side by side. */}
          <div className="flex flex-col items-center gap-3 sm:flex-row">
            {showSelection && (
              <CustomersBulkActionBar
                count={selectedCount}
                onClear={clearSelection}
                selectAllCount={selectAllCount}
                onSelectAllMatching={selectAllMatching}
                onAdjustPoints={() => setIsAdjustDialogOpen(true)}
                onExport={handleBulkExport}
                isExporting={isBulkExporting}
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

      <CustomersAdjustPointsDialog
        open={isAdjustDialogOpen}
        onOpenChange={setIsAdjustDialogOpen}
        count={selectedCount}
        isSubmitting={isAdjustSubmitting}
        onSubmit={handleBulkAdjustSubmit}
      />
    </>
  );
}
