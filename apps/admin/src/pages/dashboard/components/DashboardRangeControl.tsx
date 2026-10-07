import { __ } from '@wordpress/i18n';

import { DASHBOARD_RANGES, type DashboardRange } from '@/lib/schema/dashboard.schema';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { RANGE_LABEL } from '../dashboard-page.copy';

/** The page-level range control: it sets the window for every figure on the dashboard. */
export function DashboardRangeControl({
  range,
  onRangeChange,
}: {
  range: DashboardRange;
  onRangeChange: (range: DashboardRange) => void;
}) {
  return (
    <Segmented
      size="sm"
      value={String(range)}
      // Radix emits '' on re-clicking the active item; ignore it so a range is always active.
      onValueChange={(value) => value && onRangeChange(Number(value) as DashboardRange)}
      aria-label={__('Date range', 'yayloyalty')}
    >
      {DASHBOARD_RANGES.map((r) => (
        <SegmentedItem key={r} value={String(r)}>
          {RANGE_LABEL(r)}
        </SegmentedItem>
      ))}
    </Segmented>
  );
}
