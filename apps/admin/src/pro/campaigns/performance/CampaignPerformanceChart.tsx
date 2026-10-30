import { __, sprintf } from '@wordpress/i18n';

import { formatWPDay } from '@/lib/helpers/format.helper';
import { formatCompactPoints, formatPoints } from '@/lib/helpers/points.helper';
import type { CampaignPerformance } from '@/lib/schema/campaign-performance.schema';
import { cn } from '@/lib/utils';
import { BarChart } from '@/components/ui/bar-chart';
import { Card, CardAction, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, type ChartConfig } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import { windowLabel } from './campaign-performance.copy';

/** Fixed so the skeleton, the empty overlay and the loaded chart are the same height. */
const CHART_HEIGHT = 'h-[220px]';

/** Above this many bars, a label on every bar would overlap/clutter — the tooltip stays the only way to read a value. */
const LABEL_LIST_THRESHOLD = 14;

const CONFIG: ChartConfig = { points: { label: __('Points issued', 'yayloyalty'), color: 'var(--chart-1)' } };

type Daily = CampaignPerformance['daily'];

/** All zero or empty: the chart keeps its axes and date range, and a dashed placeholder replaces the bars. */
function isEmpty(daily: Daily): boolean {
  return daily.length === 0 || daily.every((d) => d.points === 0);
}

/**
 * One bar per day of `window`, zero-filled by the server. `window` is the last 30 days, not the
 * lifetime the totals above it cover, so the card prints `windowLabel(window)` beside its title.
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
          <BarChart
            data={daily}
            xKey="date"
            yKey="points"
            radius={2}
            topLabel={
              daily.length <= LABEL_LIST_THRESHOLD && !empty ? (value) => formatCompactPoints(value) : undefined
            }
            empty={empty}
            minTickGap={24}
            xTickFormat={(value) => formatWPDay(value, 'j M')}
            yTickFormat={(value) => formatCompactPoints(value)}
            tooltipLabel={(value) => formatWPDay(value, 'j M Y')}
            tooltipValue={(value) => formatPoints(value)}
          />
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
