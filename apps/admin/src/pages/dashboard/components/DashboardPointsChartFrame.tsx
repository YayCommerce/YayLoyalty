import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { DASHBOARD_RANGES, type DashboardRange } from '@/lib/schema/dashboard.schema';
import { cn } from '@/lib/utils';
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { RANGE_LABEL } from '../dashboard-page.copy';

/** Fixed so the skeleton, the empty overlay and the loaded chart are the same height — no jump below it. */
export const CHART_HEIGHT = 'h-[280px]';

function RangeSegmented({
  range,
  onRangeChange,
}: {
  range: DashboardRange;
  onRangeChange: (range: DashboardRange) => void;
}) {
  return (
    <Segmented
      size="sm"
      value={String(range)}
      // Radix emits '' on re-clicking the active item; ignore it so a range is always active.
      onValueChange={(value) => value && onRangeChange(Number(value) as DashboardRange)}
      aria-label={__('Date range', 'yayloyalty')}
    >
      {DASHBOARD_RANGES.map((r) => (
        <SegmentedItem key={r} value={String(r)}>
          {RANGE_LABEL(r)}
        </SegmentedItem>
      ))}
    </Segmented>
  );
}

/** Card + title + range control shared by the loaded chart and its skeleton. */
export function DashboardPointsChartFrame({
  range,
  onRangeChange,
  children,
  controls,
}: {
  range: DashboardRange;
  onRangeChange: (range: DashboardRange) => void;
  children: ReactNode;
  controls?: ReactNode;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Points issued vs redeemed', 'yayloyalty')}</CardTitle>
        <CardDescription>{__('Daily totals', 'yayloyalty')}</CardDescription>
        <CardAction className="flex flex-wrap items-center gap-2">
          {controls}
          <RangeSegmented range={range} onRangeChange={onRangeChange} />
        </CardAction>
      </CardHeader>
      {children}
    </Card>
  );
}

/** Same card, same 280px body; the range control stays live so nothing shifts when data lands (artboard 2b). */
export function DashboardPointsChartSkeleton({
  range,
  onRangeChange,
}: {
  range: DashboardRange;
  onRangeChange: (range: DashboardRange) => void;
}) {
  return (
    <DashboardPointsChartFrame range={range} onRangeChange={onRangeChange}>
      <div className={cn('flex flex-col justify-between', CHART_HEIGHT)}>
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="flex items-center gap-3">
            <Skeleton className="h-3 w-10" />
            <Skeleton className="h-px flex-1" />
          </div>
        ))}
      </div>
    </DashboardPointsChartFrame>
  );
}
