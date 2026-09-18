import type { DashboardRange } from '@/lib/schema/dashboard.schema';
import { DashboardPointsChartSkeleton } from './DashboardPointsChartFrame';
import { DashboardRecentActivitySkeleton } from './DashboardRecentActivity';
import { DashboardStatsGridSkeleton } from './DashboardStatsGrid';
import { DashboardTopEarnersSkeleton } from './DashboardTopEarners';

/** First paint: chrome renders for real, only values are placeholders, at the loaded heights so nothing reflows. */
export function DashboardSkeleton({
  range,
  onRangeChange,
}: {
  range: DashboardRange;
  onRangeChange: (range: DashboardRange) => void;
}) {
  return (
    <>
      <DashboardStatsGridSkeleton />
      <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
        <DashboardPointsChartSkeleton range={range} onRangeChange={onRangeChange} />
        <DashboardTopEarnersSkeleton />
      </div>
      <DashboardRecentActivitySkeleton />
    </>
  );
}
