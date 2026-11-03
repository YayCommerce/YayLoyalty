import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CustomersSavedSegmentRow } from '@/pro';
import type { CustomerListFilters } from '../customers-page.filters';
import type { SortDirection, SortKey } from '../customers-page.sort';
import { CustomersExportButton } from './CustomersExportButton';
import { CustomersToolbar } from './CustomersToolbar';

/**
 * Filter row + Export, arranged per build: Pro's fourth filter pushes Export
 * up beside the segment picker. The IS_PRO_BUILD early return is also the
 * guard that keeps the @/pro import out of the Lite bundle.
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
  filters: CustomerListFilters;
  onFiltersChange: (patch: Partial<CustomerListFilters>) => void;
  /** Committed filters, what Export should fetch. */
  queryFilters: CustomerListFilters;
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
