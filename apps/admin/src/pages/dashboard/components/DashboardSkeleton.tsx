import type { DashboardRange } from '@/lib/schema/dashboard.schema';
import { DashboardPointsChartSkeleton } from './DashboardPointsChartFrame';
import { DashboardRecentActivitySkeleton } from './DashboardRecentActivity';
import { DashboardStatsGridSkeleton } from './DashboardStatsGrid';
import { DashboardTopEarnersSkeleton } from './DashboardTopEarners';

/**
 * First paint (artboard 2b): everything that does not depend on the query
 * renders for real — panel titles, column headers, the range control — and
 * only values are placeholders, with the same row counts and heights as the
 * loaded panels (5 tiles, 280px chart, 3 earners, 6 activity rows) so the
 * page never reflows when data lands. No page-level spinner.
 */
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
