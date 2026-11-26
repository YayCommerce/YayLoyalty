import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/** Icon chip tint and trend colour per tile. */
const TONES = {
  blue: 'bg-chart-1/10 text-chart-1',
  green: 'bg-chart-3/10 text-chart-3',
  purple: 'bg-chart-4/10 text-chart-4',
  amber: 'bg-chart-2/10 text-chart-2',
  teal: 'bg-chart-5/10 text-chart-5',
};

export type DashboardStatTone = keyof typeof TONES;

/**
 * One stat tile. The value is a node, so this never formats a number
 * itself. `trend` sits beside the value (a sparkline or a meter); `note` is a notice's line under it.
 */
export function DashboardStatTile({
  label,
  icon,
  tone,
  value,
  subline,
  trend,
  note,
}: {
  label: string;
  icon: ReactNode;
  tone: DashboardStatTone;
  value: ReactNode;
  subline: ReactNode;
  trend?: ReactNode;
  note?: ReactNode;
}) {
  const direction = noteDirection(note);

  return (
    <Card className="gap-3 p-5">
      <TileHeader label={label} icon={icon} tone={tone} />
      <div className="flex items-end justify-between gap-3">
        <div className="flex min-w-0 flex-col gap-1">
          <div className="text-3xl leading-none font-semibold tabular-nums">{value}</div>
          <div className="text-muted-foreground truncate text-xs">{subline}</div>
        </div>
        {trend ? <div className={cn(TONES[tone], 'mb-1 bg-transparent')}>{trend}</div> : null}
      </div>
      {note ? (
        <div
          className={cn('text-xs', {
            'text-success': direction === 'up',
            'text-destructive': direction === 'down',
            'text-muted-foreground': direction === 'none',
          })}
        >
          {note}
        </div>
      ) : null}
    </Card>
  );
}

function TileHeader({ label, icon, tone }: { label: string; icon: ReactNode; tone: DashboardStatTone }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <span className="text-sm font-medium">{label}</span>
      <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-md [&_svg]:size-4', TONES[tone])}>
        {icon}
      </span>
    </div>
  );
}

function noteDirection(note: ReactNode): 'up' | 'down' | 'none' {
  if (typeof note !== 'string') return 'none';
  if (note.startsWith('▲')) return 'up';
  if (note.startsWith('▼')) return 'down';
  return 'none';
}

/** Same box, same heights, placeholder bars — so data landing never reflows the grid. */
export function DashboardStatTileSkeleton({
  label,
  icon,
  tone,
}: {
  label: string;
  icon: ReactNode;
  tone: DashboardStatTone;
}) {
  return (
    <Card className="gap-3 p-5">
      <TileHeader label={label} icon={icon} tone={tone} />
      <div className="flex flex-col gap-1">
        <Skeleton className="h-7.5 w-24" />
        <Skeleton className="h-4 w-28" />
      </div>
    </Card>
  );
}
