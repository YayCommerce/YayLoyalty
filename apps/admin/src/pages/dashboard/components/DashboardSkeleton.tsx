import { DashboardPointsChartSkeleton } from './DashboardPointsChartFrame';
import { DashboardRecentActivitySkeleton } from './DashboardRecentActivity';
import { DashboardStatsGridSkeleton } from './DashboardStatsGrid';
import { DashboardTopEarnersSkeleton } from './DashboardTopEarners';

/** First paint: chrome renders for real, only values are placeholders, at the loaded heights so nothing reflows. */
export function DashboardSkeleton() {
  return (
    <>
      <DashboardStatsGridSkeleton />
      <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
        <DashboardPointsChartSkeleton />
        <DashboardTopEarnersSkeleton />
      </div>
      <DashboardRecentActivitySkeleton />
    </>
  );
}
