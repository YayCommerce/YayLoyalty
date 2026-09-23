import { useMemo } from 'react';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useAdjustPointsPreviewQuery, useCustomerQuery } from '@/lib/queries/customer.queries';
import type { AdjustPointsPreviewInput, CustomerListRow } from '@/lib/schema/customer.schema';
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
import { toServerFilters } from '../customers-page.tier-sample-data';
import { CustomersAdjustPointsDialog } from './CustomersAdjustPointsDialog';
import { CustomersBulkActionBar } from './CustomersBulkActionBar';
import { CustomersFilterBar } from './CustomersFilterBar';
import { CustomersNoMatchState } from './CustomersNoMatchState';
import { CustomersPagination } from './CustomersPagination';
import { CustomersRebuildBanner } from './CustomersRebuildBanner';
import { CustomersTable } from './CustomersTable';

/**
 * The Customers screen body: segment row, toolbar, table, then bulk action
 * bar and pagination in one footer row. Owns row selection only; page,
 * filters and sort are server params owned by CustomersPage. Pro pieces
 * are gated by IS_PRO_BUILD directly, with no upgrade overlay.
 */
export function CustomersScreen({
  list,
  filters: filtersProp,
  queryFilters,
  sort,
  pagination,
  isRebuilding,
}: {
  /** What the list query produced for the current page. */
  list: {
    rows: CustomerListRow[];
    /** No data yet; drives the full-table skeleton. */
    isLoading: boolean;
    /** Any request in flight, including a background refetch behind placeholderData; dims the table. */
    isFetching: boolean;
    /** The whole store has zero rows, not merely these filters. */
    isStoreEmpty: boolean;
    totalItems: number;
    /** "Which filter to drop", from the same response when filters match nothing. */
    suggestions: FilterDropSuggestion[];
  };
  filters: {
    value: RowFilters;
    onChange: (patch: Partial<RowFilters>) => void;
  };
  /** The committed (debounced) filters the current list reflects; bulk actions and selection reset key off this. */
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
  /** Demo "Simulate rebuild" toggle; no real background job. */
  isRebuilding: boolean;
}) {
  const { rows, isLoading, isFetching, isStoreEmpty, totalItems, suggestions } = list;
  const { value: filters, onChange: onFiltersChange } = filtersProp;
  const { by: sortBy, dir: sortDir, onChange: onSort } = sort;
  const { page, pageCount, onChange: onPageChange } = pagination;

  const isNoMatch = !isLoading && !isStoreEmpty && rows.length === 0;

  // Not while loading: the toolbar stays live and only the table body shows skeletons.
  const controlsDisabled = isStoreEmpty || isRebuilding;
  const showSelection = IS_PRO_BUILD && !isRebuilding;

  // Selection survives page navigation; 'all' is a flag, never a fetch of every id.
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
    acknowledgementResetKey,
  } = useCustomersBulkActions({ rows, queryFilters, sort, selection, setSelection });

  // One ticked row adjusts like the drawer: against that customer's own
  // record. 0 keeps the detail query disabled while the dialog is closed.
  const singleId = selection.mode === 'partial' && selection.ids.size === 1 ? [...selection.ids][0] : 0;
  const customerQuery = useCustomerQuery(isAdjustDialogOpen ? singleId : 0);

  // 'all' mode asks the server to COUNT the filter; a tick list knows its own
  // count, so it sends no filters and reads only liability and rate.
  const previewInput = useMemo<AdjustPointsPreviewInput>(
    () => (selection.mode === 'all' ? { filters: toServerFilters(queryFilters) } : {}),
    [selection.mode, queryFilters],
  );
  const preview = useAdjustPointsPreviewQuery(previewInput, isAdjustDialogOpen && singleId === 0);

  // 'all' mode can carry deselected rows; the server counts the filter, not
  // the deselection. Same arithmetic as selectionCount() in customers-page.selection.ts.
  const excludedCount = selection.mode === 'all' ? selection.excludedIds.size : 0;

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
        target={singleId > 0 ? { kind: 'single', customer: customerQuery } : { kind: 'bulk', preview }}
        excludedCount={excludedCount}
        selectionMode={selection.mode}
        filters={queryFilters}
        rowsOnPage={rows.length}
        acknowledgementResetKey={acknowledgementResetKey}
      />
    </>
  );
}
