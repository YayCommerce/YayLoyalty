import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CustomersSavedSegmentRow } from '@/pro';
import type { RowFilters } from '../customers-page.filter-rows';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';
import { CustomersExportButton } from './CustomersExportButton';
import { CustomersToolbar } from './CustomersToolbar';

/**
 * Search/filter row + Export CSV, arranged differently per build — split out
 * of CustomersScreen.tsx to keep that file under the 200-line cap.
 *
 * Pro's 4th filter (Tier) means Search+Balance+Expiring+Tier+Export would
 * crowd one row, so Export moves up to its own row next to the saved segment
 * picker. Free has 3 filters — Export fits inline in the toolbar instead; a
 * near-empty row with nothing on the left to balance it would look broken.
 *
 * `CustomersSavedSegmentRow` comes from `@/pro`: this `if (IS_PRO_BUILD)`
 * early return is not just a layout choice, it is also the guard that keeps
 * that import (and the rest of `src/pro/`) out of the Lite bundle — see
 * `@/pro`'s barrel comment.
 */
export function CustomersFilterBar({
  disabled,
  exportDisabled,
  filters,
  onFiltersChange,
  queryFilters,
  sort,
}: {
  disabled: boolean;
  exportDisabled: boolean;
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  /** Committed/debounced filters — what Export should actually fetch. See CustomersScreen.tsx. */
  queryFilters: RowFilters;
  sort: { by: SortKey; dir: SortDirection };
}) {
  if (IS_PRO_BUILD) {
    return (
      <>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <CustomersSavedSegmentRow disabled={disabled} filters={filters} onFiltersChange={onFiltersChange} />
          <CustomersExportButton disabled={exportDisabled} queryFilters={queryFilters} sort={sort} />
        </div>
        <CustomersToolbar filters={filters} onFiltersChange={onFiltersChange} disabled={disabled} />
      </>
    );
  }

  return (
    <CustomersToolbar
      filters={filters}
      onFiltersChange={onFiltersChange}
      disabled={disabled}
      exportSlot={<CustomersExportButton disabled={exportDisabled} queryFilters={queryFilters} sort={sort} />}
    />
  );
}
