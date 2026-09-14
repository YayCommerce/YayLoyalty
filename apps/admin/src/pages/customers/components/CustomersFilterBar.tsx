import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { RowFilters } from '../customers-page.filter-rows';
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
}: {
  disabled: boolean;
  exportDisabled: boolean;
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
}) {
  if (IS_PRO_BUILD) {
    return (
      <>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <CustomersSavedSegmentRow disabled={disabled} />
          <CustomersExportButton disabled={exportDisabled} />
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
      exportSlot={<CustomersExportButton disabled={exportDisabled} />}
    />
  );
}
