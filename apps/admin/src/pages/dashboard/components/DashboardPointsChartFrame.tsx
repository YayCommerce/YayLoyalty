import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/** Fixed so the skeleton, the empty overlay and the loaded chart are the same height — no jump below it. */
export const CHART_HEIGHT = 'h-[280px]';

/** Card + title + header controls shared by the loaded chart and its skeleton. */
export function DashboardPointsChartFrame({ children, controls }: { children: ReactNode; controls?: ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Points issued vs redeemed', 'yayloyalty')}</CardTitle>
        <CardDescription>{__('Daily totals', 'yayloyalty')}</CardDescription>
        {controls && <CardAction className="flex flex-wrap items-center gap-2">{controls}</CardAction>}
      </CardHeader>
      {children}
    </Card>
  );
}

/** Same card, same 280px body, so nothing shifts when data lands. */
export function DashboardPointsChartSkeleton() {
  return (
    <DashboardPointsChartFrame>
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
