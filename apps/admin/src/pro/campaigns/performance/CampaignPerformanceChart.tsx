import { Bar, BarChart, LabelList, XAxis, YAxis } from 'recharts';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPDay } from '@/lib/helpers/format.helper';
import { formatCompactPoints, formatPoints } from '@/lib/helpers/points.helper';
import type { CampaignPerformance } from '@/lib/schema/campaign-performance.schema';
import { cn } from '@/lib/utils';
import { Card, CardAction, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import { windowLabel } from './campaign-performance.copy';

/** Fixed so the skeleton, the empty overlay and the loaded chart are the same height. */
const CHART_HEIGHT = 'h-[220px]';

/** Above this many bars, `LabelList` on every bar would overlap/clutter — the tooltip stays the only way to read a value. */
const LABEL_LIST_THRESHOLD = 14;

const CONFIG: ChartConfig = { points: { label: __('Points issued', 'yayloyalty'), color: 'var(--chart-1)' } };

type Daily = CampaignPerformance['daily'];

/** All-zero (or literally empty) — the window still draws its axes and date range; only the bars/tooltip/labels are swapped for a dashed placeholder. */
function isEmpty(daily: Daily): boolean {
  return daily.length === 0 || daily.every((d) => d.points === 0);
}

/**
 * One bar per day of `window`, zero-filled by the server. `window` is the
 * chart's own last-30-days span, not the lifetime totals shown above it —
 * see CampaignPerformanceRestController's class docblock ("TWO TIME BASES
 * ON PURPOSE") — which is exactly why this card prints `windowLabel(window)`
 * next to its own title rather than assuming the reader already knows.
 */
export function CampaignPerformanceChart({ window, daily }: { window: CampaignPerformance['window']; daily: Daily }) {
  const empty = isEmpty(daily);

  return (
    <Card className="min-w-0 flex-1">
      <CardHeader>
        <CardTitle>{__('Points issued by day', 'yayloyalty')}</CardTitle>
        <CardAction className="text-muted-foreground text-xs">{windowLabel(window)}</CardAction>
      </CardHeader>
      <div className="relative">
        <ChartContainer config={CONFIG} className={cn('aspect-auto w-full', CHART_HEIGHT)}>
          <BarChart data={daily} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
            <XAxis
              dataKey="date"
              tickLine={false}
              axisLine={false}
              minTickGap={24}
              tickFormatter={(value: string) => parseWPDay(value, 'j M')}
            />
            <YAxis
              tickLine={false}
              axisLine={false}
              width={56}
              allowDecimals={false}
              // An all-zero window still draws a "0" axis line and the dates.
              domain={[0, empty ? 1 : 'auto']}
              ticks={empty ? [0] : undefined}
              tickFormatter={(value: number) => formatCompactPoints(value)}
            />
            {!empty && (
              <ChartTooltip
                content={
                  <ChartTooltipContent
                    labelFormatter={(value: string) => parseWPDay(value, 'j M Y')}
                    formatter={(value) => (
                      <span className="font-mono font-medium tabular-nums">{formatPoints(Number(value))}</span>
                    )}
                  />
                }
              />
            )}
            <Bar dataKey="points" fill="var(--color-points)" radius={2} isAnimationActive={false}>
              {daily.length <= LABEL_LIST_THRESHOLD && !empty && (
                <LabelList
                  dataKey="points"
                  position="top"
                  className="fill-foreground text-xs"
                  formatter={(value: number) => formatCompactPoints(value)}
                />
              )}
            </Bar>
          </BarChart>
        </ChartContainer>
        {empty && (
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center pb-6">
            <div className="border-muted-foreground/40 max-w-[320px] rounded-md border border-dashed px-4 py-3 text-center">
              <div className="text-sm font-semibold">{__('Nothing to chart yet', 'yayloyalty')}</div>
              <div className="text-muted-foreground mt-1 text-xs">
                {sprintf(
                  /* translators: %d: number of days in the reporting window. */
                  __('One bar per day of the %d-day window, drawn as it earns.', 'yayloyalty'),
                  daily.length,
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </Card>
  );
}

export function CampaignPerformanceChartSkeleton() {
  return (
    <Card className="min-w-0 flex-1">
      <CardHeader>
        <Skeleton className="h-5 w-40" />
      </CardHeader>
      <div className={cn('flex flex-col justify-between px-6 pb-6', CHART_HEIGHT)}>
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="flex items-center gap-3">
            <Skeleton className="h-3 w-10" />
            <Skeleton className="h-px flex-1" />
          </div>
        ))}
      </div>
    </Card>
  );
}
