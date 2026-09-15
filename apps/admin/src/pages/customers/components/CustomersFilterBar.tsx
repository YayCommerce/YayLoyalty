import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { RowFilters } from '../customers-page.filter-rows';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';
import { CustomersExportButton } from './CustomersExportButton';
import { CustomersSavedSegmentRow } from './CustomersSavedSegmentRow';
import { CustomersToolbar } from './CustomersToolbar';

/**
 * Search/filter row + Export CSV, arranged differently per build — split out
 * of CustomersScreen.tsx to keep that file under the 200-line cap.
 *
 * Pro's 4th filter (Tier) means Search+Balance+Expiring+Tier+Export would
 * crowd one row, so Export moves up to its own row next to the saved segment
 * picker. Free has 3 filters — Export fits inline in the toolbar instead; a
 * near-empty row with nothing on the left to balance it would look broken.
 */
export function CustomersFilterBar({
  disabled,
  exportDisabled,
  filters,
  onFiltersChange,
  queryFilters,
  sort,
  isFetching,
}: {
  disabled: boolean;
  exportDisabled: boolean;
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  /** Committed/debounced filters — what Export should actually fetch. See CustomersScreen.tsx. */
  queryFilters: RowFilters;
  sort: { by: SortKey; dir: SortDirection };
  /** The list query has a request in flight — see CustomersToolbar.tsx. */
  isFetching: boolean;
}) {
  if (IS_PRO_BUILD) {
    return (
      <>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <CustomersSavedSegmentRow disabled={disabled} />
          <CustomersExportButton disabled={exportDisabled} queryFilters={queryFilters} sort={sort} />
        </div>
        <CustomersToolbar
          filters={filters}
          onFiltersChange={onFiltersChange}
          disabled={disabled}
          isFetching={isFetching}
        />
      </>
    );
  }

  return (
    <CustomersToolbar
      filters={filters}
      onFiltersChange={onFiltersChange}
      disabled={disabled}
      isFetching={isFetching}
      exportSlot={<CustomersExportButton disabled={exportDisabled} queryFilters={queryFilters} sort={sort} />}
    />
  );
}
