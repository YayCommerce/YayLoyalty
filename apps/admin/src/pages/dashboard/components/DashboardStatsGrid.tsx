import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import type { DashboardRange, DashboardStats } from '@/lib/schema/dashboard.schema';
import { PointsValue } from '@/components/ui/custom/points-value';
import { ordersSubline, rangeSubline } from '../dashboard-page.copy';
import { DashboardStatTile, DashboardStatTileSkeleton } from './DashboardStatTile';

/** Label order is fixed; the skeleton reuses it so both states share one grid. */
export const STAT_LABELS = () => [
  __('Members', 'yayloyalty'),
  __('Points issued', 'yayloyalty'),
  __('Points redeemed', 'yayloyalty'),
  __('Outstanding liability', 'yayloyalty'),
  __('Redemption rate', 'yayloyalty'),
];

/** auto-fit / minmax(184px, 1fr): five tiles on one row at 1440, wrapping as equals below. */
const GRID = 'grid gap-4 grid-cols-[repeat(auto-fit,minmax(184px,1fr))]';

/** The five stat tiles. `deltaOverrides` lets the notice strip replace a delta line. */
export function DashboardStatsGrid({
  stats,
  range,
  deltaOverrides = {},
}: {
  stats: DashboardStats;
  range: DashboardRange;
  deltaOverrides?: Partial<Record<keyof DashboardStats, ReactNode>>;
}) {
  const [members, issued, redeemed, liability, rate] = STAT_LABELS();

  return (
    <div className={GRID}>
      <DashboardStatTile
        label={members}
        value={<PointsValue value={stats.members.value} />}
        subline={
          stats.members.value === 0 ? __('no one has joined yet', 'yayloyalty') : __('total enrolled', 'yayloyalty')
        }
        delta={stats.members.prev_delta_pct}
        range={range}
        deltaOverride={deltaOverrides.members}
      />
      <DashboardStatTile
        label={issued}
        value={<PointsValue value={stats.points_issued.value} />}
        subline={rangeSubline(range)}
        delta={stats.points_issued.prev_delta_pct}
        range={range}
        deltaOverride={deltaOverrides.points_issued}
      />
      <DashboardStatTile
        label={redeemed}
        value={<PointsValue value={stats.points_redeemed.value} />}
        subline={rangeSubline(range)}
        delta={stats.points_redeemed.prev_delta_pct}
        range={range}
        deltaOverride={deltaOverrides.points_redeemed}
      />
      <DashboardStatTile
        label={liability}
        value={<PointsValue value={stats.liability.points} />}
        subline={__('points members have not spent', 'yayloyalty')}
        delta={stats.liability.prev_delta_pct}
        range={range}
        deltaOverride={deltaOverrides.liability}
      />
      <DashboardStatTile
        label={rate}
        value={
          stats.redemption_rate.rate === null
            ? '—'
            : `${stats.redemption_rate.rate.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`
        }
        subline={ordersSubline(stats.redemption_rate.orders_with_points, stats.redemption_rate.orders_total)}
        delta={stats.redemption_rate.prev_delta_pct}
        range={range}
        deltaOverride={deltaOverrides.redemption_rate}
      />
    </div>
  );
}

export function DashboardStatsGridSkeleton() {
  return (
    <div className={GRID}>
      {STAT_LABELS().map((label) => (
        <DashboardStatTileSkeleton key={label} label={label} />
      ))}
    </div>
  );
}
