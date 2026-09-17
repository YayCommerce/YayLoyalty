import { useMemo, type ReactNode } from 'react';
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import { __ } from '@wordpress/i18n';

import { parseWPDay } from '@/lib/helpers/format.helper';
import { formatCompactPoints, formatPoints } from '@/lib/helpers/points.helper';
import type { DashboardRange, DashboardSeriesPoint } from '@/lib/schema/dashboard.schema';
import { cn } from '@/lib/utils';
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart';
import { isSeriesEmpty } from '../dashboard-page.copy';
import { CHART_HEIGHT, DashboardPointsChartFrame } from './DashboardPointsChartFrame';

/** One thin per-campaign line, merged into the rows by date. */
export type ExtraSeries = { key: string; label: string; data: { date: string; issued: number }[] };

const NO_EXTRA_SERIES: ExtraSeries[] = [];

/** Per-campaign lines take the palette after issued/redeemed. */
const EXTRA_COLORS = ['var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

/**
 * Issued-vs-redeemed line chart with the range control. `children` is the
 * header controls slot; `extraSeries` adds per-campaign lines. An all-zero
 * series still draws its axes under an overlay.
 */
export function DashboardPointsChart({
  series,
  range,
  onRangeChange,
  extraSeries = NO_EXTRA_SERIES,
  children,
}: {
  series: DashboardSeriesPoint[];
  range: DashboardRange;
  onRangeChange: (range: DashboardRange) => void;
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
    <DashboardPointsChartFrame range={range} onRangeChange={onRangeChange} controls={children}>
      <div className="relative">
        <ChartContainer config={config} className={cn('aspect-auto w-full', CHART_HEIGHT)}>
          <LineChart data={rows} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
            <CartesianGrid vertical={false} />
            <XAxis
              dataKey="date"
              tickLine={false}
              axisLine={false}
              minTickGap={32}
              tickFormatter={(value: string) => parseWPDay(value, 'j M')}
            />
            <YAxis
              tickLine={false}
              axisLine={false}
              width={56}
              allowDecimals={false}
              // An all-zero series still draws a "0" axis line and the dates.
              domain={[0, empty ? 1 : 'auto']}
              ticks={empty ? [0] : undefined}
              tickFormatter={(value: number) => formatCompactPoints(value)}
            />
            {!empty && (
              <ChartTooltip
                content={
                  <ChartTooltipContent
                    labelFormatter={(value: string) => parseWPDay(value, 'j M Y')}
                    formatter={(value, name) => (
                      <div className="flex w-full items-center justify-between gap-4">
                        <span className="text-muted-foreground">{config[name as string]?.label ?? name}</span>
                        <span className="font-mono font-medium tabular-nums">{formatPoints(Number(value))}</span>
                      </div>
                    )}
                  />
                }
              />
            )}
            <ChartLegend content={<ChartLegendContent />} />
            <Line
              type="monotone"
              dataKey="issued"
              stroke="var(--color-issued)"
              strokeWidth={2}
              dot={false}
              isAnimationActive={false}
            />
            <Line
              type="monotone"
              dataKey="redeemed"
              stroke="var(--color-redeemed)"
              strokeWidth={2}
              dot={false}
              isAnimationActive={false}
            />
            {extraSeries.map((s) => (
              <Line
                key={s.key}
                type="monotone"
                dataKey={s.key}
                stroke={`var(--color-${s.key})`}
                strokeWidth={1}
                dot={false}
                isAnimationActive={false}
              />
            ))}
          </LineChart>
        </ChartContainer>
        {empty && (
          <div className="text-muted-foreground pointer-events-none absolute inset-0 flex items-center justify-center pb-8 text-sm">
            {__('No points issued yet. This chart fills in as orders complete.', 'yayloyalty')}
          </div>
        )}
      </div>
    </DashboardPointsChartFrame>
  );
}
