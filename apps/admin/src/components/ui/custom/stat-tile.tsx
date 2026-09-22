import type { ReactNode } from 'react';

import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * A stat tile with no comparison line: label, value, sub-line only. Kept
 * separate from `DashboardStatTile` (which adds a delta line) so a change
 * here can't affect the Dashboard.
 */
export function StatTile({
  label,
  value,
  subline,
  badge,
}: {
  label: string;
  value: ReactNode;
  subline: ReactNode;
  badge?: ReactNode;
}) {
  return (
    <Card className="gap-1 p-4">
      <div className="text-muted-foreground flex items-center gap-2 text-xs font-medium tracking-wide uppercase">
        {label}
        {badge}
      </div>
      <div className="text-2xl font-semibold tabular-nums">{value}</div>
      <div className="text-muted-foreground text-xs">{subline}</div>
    </Card>
  );
}

/** Same box, same heights, placeholder bars — so data landing never reflows the grid. */
export function StatTileSkeleton({ label }: { label: string }) {
  return (
    <Card className="gap-1 p-4">
      <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</div>
      <Skeleton className="my-1 h-6 w-24" />
      <Skeleton className="h-3 w-28" />
    </Card>
  );
}
