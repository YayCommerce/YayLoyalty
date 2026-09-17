import type { ReactNode } from 'react';

import type { DashboardRange } from '@/lib/schema/dashboard.schema';
import { cn } from '@/lib/utils';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { deltaLine } from '../dashboard-page.copy';

/**
 * One stat tile: label, value, sub-line, delta line. The value is a node so
 * callers pass `<PointsValue>`, a currency string or "—" — this component
 * never formats a number itself. `deltaOverride` replaces the delta line
 * (Phase 5: "▲ 41,200 of it yesterday"); `badge` sits beside the label
 * (Phase 6: the PRO badge on Referrals).
 */
export function DashboardStatTile({
  label,
  value,
  subline,
  delta,
  range,
  deltaOverride,
  badge,
}: {
  label: string;
  value: ReactNode;
  subline: ReactNode;
  delta: number | null;
  range: DashboardRange;
  deltaOverride?: ReactNode;
  badge?: ReactNode;
}) {
  const line = deltaLine(delta, range);
  // An override carries its own arrow (or none); it must not inherit the hidden delta's colour.
  const direction = deltaOverride === undefined ? line.direction : overrideDirection(deltaOverride);

  return (
    <Card className="gap-1 p-4">
      <div className="text-muted-foreground flex items-center gap-2 text-xs font-medium tracking-wide uppercase">
        {label}
        {badge}
      </div>
      <div className="text-2xl font-semibold tabular-nums">{value}</div>
      <div className="text-muted-foreground text-xs">{subline}</div>
      <div
        className={cn('mt-1 text-xs', {
          'text-success': direction === 'up',
          'text-destructive': direction === 'down',
          'text-muted-foreground': direction === 'flat' || direction === 'none',
        })}
      >
        {deltaOverride ?? line.text}
      </div>
    </Card>
  );
}

function overrideDirection(override: ReactNode): 'up' | 'down' | 'none' {
  if (typeof override !== 'string') return 'none';
  if (override.startsWith('▲')) return 'up';
  if (override.startsWith('▼')) return 'down';
  return 'none';
}

/** Same box, same heights, placeholder bars — so data landing never reflows the grid. */
export function DashboardStatTileSkeleton({ label }: { label: string }) {
  return (
    <Card className="gap-1 p-4">
      <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</div>
      <Skeleton className="my-1 h-6 w-24" />
      <Skeleton className="h-3 w-28" />
      <Skeleton className="mt-1 h-3 w-32" />
    </Card>
  );
}
