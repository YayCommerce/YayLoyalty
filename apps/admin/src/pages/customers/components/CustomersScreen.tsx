import { useMemo } from 'react';

import { Card, CardContent } from '@/components/ui/card';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '../customers-page.filter-rows';
import { countActiveFilters, getFilterDropSuggestions } from '../customers-page.filter-suggestions';
import type { MockCustomer } from '../customers-page.mock-data';
import { CustomersBulkActionBar } from './CustomersBulkActionBar';
import { CustomersNoMatchState } from './CustomersNoMatchState';
import { CustomersPagination } from './CustomersPagination';
import { CustomersSavedSegmentRow } from './CustomersSavedSegmentRow';
import { CustomersTable } from './CustomersTable';
import { CustomersToolbar } from './CustomersToolbar';

type HeaderSelectionState = 'none' | 'partial' | 'all';

/**
 * The Customers screen body: [saved segment row] → toolbar → [bulk action
 * bar] → table → pagination. Bracketed pieces are Pro-only, gated directly by
 * `__IS_PRO__` — no demo switcher, no upgrade overlay for a feature that
 * simply adds columns rather than walling off the page (same split
 * `CampaignsPage.tsx` uses, and the `useIsPremium`-style check yay-reviews'
 * `PremiumGate` is built on).
 */
export function CustomersScreen({
  allRows,
  rows,
  isLoading,
  filters,
  onFiltersChange,
  selected,
  headerSelection,
  onToggleAll,
  onToggleRow,
  onClearSelection,
}: {
  /** Unfiltered row count, for the pagination "{shown} of {total}" label. */
  allRows: MockCustomer[];
  /** Already search/filter-narrowed by the page — see CustomersPage.tsx. */
  rows: MockCustomer[];
  /** `useCustomersDemoQuery` still in flight. */
  isLoading: boolean;
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  selected: Set<number>;
  headerSelection: HeaderSelectionState;
  onToggleAll: () => void;
  onToggleRow: (userId: number) => void;
  onClearSelection: () => void;
}) {
  // A genuinely empty store, not a search/filter matching nobody, and not
  // "still loading" either — see CustomersTable.tsx / CustomersEmptyState.tsx
  // for why the first distinction matters. The toolbar goes inert (but stays
  // in place) once there's nothing to search, filter or export; while
  // loading it stays live per the design — only the table body skeletons.
  const isStoreEmpty = !isLoading && allRows.length === 0;
  const isNoMatch = !isLoading && !isStoreEmpty && rows.length === 0;

  // Real counts, not canned copy — see customers-page.filter-suggestions.ts.
  const suggestions = useMemo(
    () => (isNoMatch ? getFilterDropSuggestions(allRows, filters, __IS_PRO__) : []),
    [isNoMatch, allRows, filters],
  );

  return (
    <Card>
      <CardContent className="flex flex-col gap-4">
        {__IS_PRO__ && <CustomersSavedSegmentRow disabled={isStoreEmpty} />}

        <CustomersToolbar
          filters={filters}
          onFiltersChange={onFiltersChange}
          disabled={isStoreEmpty}
          exportDisabled={isLoading || rows.length === 0}
        />

        {__IS_PRO__ && selected.size > 0 && (
          <CustomersBulkActionBar count={selected.size} onClear={onClearSelection} />
        )}

        <CustomersTable
          rows={rows}
          isLoading={isLoading}
          isStoreEmpty={isStoreEmpty}
          noMatchContent={
            isNoMatch ? (
              <CustomersNoMatchState
                activeCount={countActiveFilters(filters, __IS_PRO__)}
                suggestions={suggestions}
                onUseSuggestion={(key) => onFiltersChange({ [key]: DEFAULT_ROW_FILTERS[key] })}
                onClearAll={() => onFiltersChange(DEFAULT_ROW_FILTERS)}
              />
            ) : undefined
          }
          showProColumns={__IS_PRO__}
          headerSelection={headerSelection}
          onToggleAll={onToggleAll}
          selected={selected}
          onToggleRow={onToggleRow}
        />

        <CustomersPagination shown={rows.length} total={allRows.length} isLoading={isLoading} />
      </CardContent>
    </Card>
  );
}
