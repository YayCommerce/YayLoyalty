import { useMemo, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { DAILY_POINTS_CHART_FORMAT } from '@/lib/helpers/points.helper';
import type { DashboardSeriesPoint } from '@/lib/schema/dashboard.schema';
import { type ChartConfig } from '@/components/ui/chart';
import { LineChart } from '@/components/ui/line-chart';
import { isSeriesEmpty } from '../dashboard-page.copy';
import { CHART_HEIGHT, DashboardPointsChartFrame } from './DashboardPointsChartFrame';

/** One thin per-campaign line, merged into the rows by date. */
export type ExtraSeries = { key: string; label: string; data: { date: string; issued: number }[] };

const NO_EXTRA_SERIES: ExtraSeries[] = [];

/** Per-campaign lines take the palette after issued/redeemed. */
const EXTRA_COLORS = ['var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

/**
 * Issued-vs-redeemed line chart. `children` is the header controls slot; `extraSeries` adds per-campaign lines. An all-zero
 * series still draws its axes under an overlay.
 */
export function DashboardPointsChart({
  series,
  extraSeries = NO_EXTRA_SERIES,
  children,
}: {
  series: DashboardSeriesPoint[];
  extraSeries?: ExtraSeries[];
  children?: ReactNode;
}) {
  const config = useMemo<ChartConfig>(
    () => ({
      issued: {
        label: extraSeries.length ? __('Issued, all campaigns', 'yayloyalty') : __('Issued', 'yayloyalty'),
        color: 'var(--chart-1)',
      },
      redeemed: { label: __('Redeemed', 'yayloyalty'), color: 'var(--chart-2)' },
      ...Object.fromEntries(
        extraSeries.map((s, i) => [s.key, { label: s.label, color: EXTRA_COLORS[i % EXTRA_COLORS.length] }]),
      ),
    }),
    [extraSeries],
  );

  // Per-campaign lines are thinner than the totals.
  const strokeWidths = useMemo(() => Object.fromEntries(extraSeries.map((s) => [s.key, 1])), [extraSeries]);

  const rows = useMemo(() => {
    const byDate = new Map(series.map((p) => [p.date, { ...p } as Record<string, string | number>]));
    for (const s of extraSeries) {
      for (const p of s.data) {
        const row = byDate.get(p.date);
        if (row) row[s.key] = p.issued;
      }
    }
    return [...byDate.values()];
  }, [series, extraSeries]);

  const empty = isSeriesEmpty(series);

  return (
    <DashboardPointsChartFrame controls={children}>
      <div className="relative">
        <LineChart
          config={config}
          data={rows}
          xKey="date"
          format={DAILY_POINTS_CHART_FORMAT}
          className={CHART_HEIGHT}
          empty={empty}
          grid
          legend
          minTickGap={32}
          strokeWidths={strokeWidths}
        />
        {empty && (
          <div className="text-muted-foreground pointer-events-none absolute inset-0 flex items-center justify-center pb-8 text-sm">
            {__('No points issued yet. This chart fills in as orders complete.', 'yayloyalty')}
          </div>
        )}
      </div>
    </DashboardPointsChartFrame>
  );
}
