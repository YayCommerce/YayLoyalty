import type { ReactNode } from 'react';
import { CirclePlus, Gift, Percent, Users, Wallet } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { formatPercent } from '@/lib/helpers/format.helper';
import type { DashboardRange, DashboardSeriesPoint, DashboardStats } from '@/lib/schema/dashboard.schema';
import { PointsValue } from '@/components/ui/custom/points-value';
import { ordersSubline, perDayAverage, perMemberAverage } from '../dashboard-page.copy';
import { DashboardSparkline } from './DashboardSparkline';
import { DashboardStatTile, DashboardStatTileSkeleton, type DashboardStatTone } from './DashboardStatTile';

/** Label order is fixed; the skeleton reuses it so both states share one grid. */
const TILES = (): { label: string; icon: ReactNode; tone: DashboardStatTone }[] => [
  { label: __('Members', 'yayloyalty'), icon: <Users />, tone: 'blue' },
  { label: __('Points issued', 'yayloyalty'), icon: <CirclePlus />, tone: 'green' },
  { label: __('Points redeemed', 'yayloyalty'), icon: <Gift />, tone: 'purple' },
  { label: __('Unspent points', 'yayloyalty'), icon: <Wallet />, tone: 'amber' },
  { label: __('Redemption rate', 'yayloyalty'), icon: <Percent />, tone: 'teal' },
];

/** auto-fit / minmax(200px, 1fr): five tiles on one row at 1440, wrapping as equals below. */
const GRID = 'grid gap-4 grid-cols-[repeat(auto-fit,minmax(200px,1fr))]';

/** The five stat tiles. `deltaOverrides` are the notice strip's lines under a tile. */
export function DashboardStatsGrid({
  stats,
  series,
  range,
  deltaOverrides = {},
}: {
  stats: DashboardStats;
  series: DashboardSeriesPoint[];
  range: DashboardRange;
  deltaOverrides?: Partial<Record<keyof DashboardStats, ReactNode>>;
}) {
  const [members, issued, redeemed, unspent, rate] = TILES();

  return (
    <div className={GRID}>
      <DashboardStatTile
        {...members}
        value={<PointsValue value={stats.members.value} />}
        subline={
          stats.members.value === 0 ? __('no one has joined yet', 'yayloyalty') : __('total enrolled', 'yayloyalty')
        }
        note={deltaOverrides.members}
      />
      <DashboardStatTile
        {...issued}
        value={<PointsValue value={stats.points_issued.value} />}
        subline={perDayAverage(stats.points_issued.value, range)}
        trend={<DashboardSparkline values={series.map((p) => p.issued)} />}
        note={deltaOverrides.points_issued}
      />
      <DashboardStatTile
        {...redeemed}
        value={<PointsValue value={stats.points_redeemed.value} />}
        subline={perDayAverage(stats.points_redeemed.value, range)}
        trend={<DashboardSparkline values={series.map((p) => p.redeemed)} />}
        note={deltaOverrides.points_redeemed}
      />
      <DashboardStatTile
        {...unspent}
        value={<PointsValue value={stats.liability.points} />}
        subline={perMemberAverage(stats.liability.points, stats.members.value)}
        note={deltaOverrides.liability}
      />
      <DashboardStatTile
        {...rate}
        value={stats.redemption_rate.rate === null ? '—' : formatPercent(stats.redemption_rate.rate, 1)}
        subline={ordersSubline(stats.redemption_rate.orders_with_redemption, stats.redemption_rate.orders_total)}
        trend={<RateMeter rate={stats.redemption_rate.rate} />}
        note={deltaOverrides.redemption_rate}
      />
    </div>
  );
}

/** A thin bar filled to the redemption rate. */
function RateMeter({ rate }: { rate: number | null }) {
  return (
    <div aria-hidden className="bg-muted h-1.5 w-20 overflow-hidden rounded-full">
      <div className="h-full rounded-full bg-current" style={{ width: `${Math.min(Math.max(rate ?? 0, 0), 100)}%` }} />
    </div>
  );
}

export function DashboardStatsGridSkeleton() {
  return (
    <div className={GRID}>
      {TILES().map((tile) => (
        <DashboardStatTileSkeleton key={tile.label} {...tile} />
      ))}
    </div>
  );
}
