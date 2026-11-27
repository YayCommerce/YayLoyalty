import { DASHBOARD_ROW } from './DashboardPanel';
import { DashboardPointsChartSkeleton } from './DashboardPointsChartFrame';
import { DashboardRecentActivitySkeleton } from './DashboardRecentActivity';
import { DashboardStatsGridSkeleton } from './DashboardStatsGrid';
import { DashboardTopBalancesSkeleton } from './DashboardTopBalances';

/** First paint: chrome renders for real, only values are placeholders, at the loaded heights so nothing reflows. */
export function DashboardSkeleton() {
  return (
    <>
      <DashboardStatsGridSkeleton />
      <div className={DASHBOARD_ROW}>
        <DashboardPointsChartSkeleton />
        <DashboardTopBalancesSkeleton />
      </div>
      <div className={DASHBOARD_ROW}>
        <DashboardRecentActivitySkeleton />
      </div>
    </>
  );
}
