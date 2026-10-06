import { useMemo } from 'react';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useAdjustPointsPreviewQuery, useCustomerQuery } from '@/lib/queries/customer.queries';
import type { AdjustPointsPreviewInput, CustomerListRow } from '@/lib/schema/customer.schema';
import { Card, CardContent } from '@/components/ui/card';
import { useCustomersBulkActions } from '../customers-page.bulk-actions';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '../customers-page.filter-rows';
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
}) {
  const { rows, isLoading, isFetching, isStoreEmpty, totalItems, suggestions } = list;
  const { value: filters, onChange: onFiltersChange } = filtersProp;
  const { by: sortBy, dir: sortDir, onChange: onSort } = sort;
  const { page, pageCount, onChange: onPageChange } = pagination;

  const isNoMatch = !isLoading && !isStoreEmpty && rows.length === 0;

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
    recountedCount,
  } = useCustomersBulkActions({ rows, queryFilters, sort, selection, setSelection });

  // One ticked row adjusts like the drawer, against that customer's own record. Keyed on the
  // selection, not on the dialog opening, or the dialog's read on open would use the previous key.
  const singleId = selection.mode === 'partial' && selection.ids.size === 1 ? [...selection.ids][0] : 0;
  const customerQuery = useCustomerQuery(singleId);

  // 'all' mode asks the server to COUNT the filter; a tick list knows its own
  // count, so it sends no filters and reads only liability and rate.
  const previewInput = useMemo<AdjustPointsPreviewInput>(
    () => (selection.mode === 'all' ? { filters: queryFilters } : {}),
    [selection.mode, queryFilters],
  );
  const preview = useAdjustPointsPreviewQuery(previewInput, isAdjustDialogOpen && singleId === 0);

  // 'all' mode can carry deselected rows: the server counts the filter, not the deselection.
  const excludedCount = selection.mode === 'all' ? selection.excludedIds.size : 0;

  return (
    <>
      <Card>
        <CardContent className="flex flex-col gap-4">
          <CustomersFilterBar
            // Not while loading: the toolbar stays live and only the table body shows skeletons.
            disabled={isStoreEmpty}
            exportDisabled={isStoreEmpty || isLoading || rows.length === 0}
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
                  activeCount={countActiveFilters(filters)}
                  suggestions={suggestions}
                  onUseSuggestion={(suggestion) => onFiltersChange(patchForSuggestion(suggestion, filters))}
                  onClearAll={() => onFiltersChange(DEFAULT_ROW_FILTERS)}
                />
              ) : undefined
            }
            sort={{ by: sortBy, dir: sortDir, onChange: onSort }}
            selection={{
              enabled: IS_PRO_BUILD,
              header: headerSelection,
              selected: selectedOnPage,
              onToggle: toggleRow,
              onToggleAll: toggleAll,
            }}
          />

          <div className="flex flex-col items-center gap-3 sm:flex-row">
            {IS_PRO_BUILD && (
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
        recountedCount={recountedCount}
      />
    </>
  );
}
