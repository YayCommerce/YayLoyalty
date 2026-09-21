import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * One stat cell: label above value, optional sub-line beneath. Pure
 * presentation — callers pass already-formatted strings (`formatPoints`,
 * never `formatCompactPoints`; figures are never abbreviated in this drawer).
 *
 * Borderless, exactly as 1e/1f/3c draw it: the stats are a bare grid of
 * label+value pairs, not cards. `size="lg"` is the two headline figures
 * (Balance, Pending); everything else is the smaller secondary size, padded
 * down a little so its baseline sits under the taller row. `muted` dims the
 * value only — Pending's figure is grey in every artboard so it reads as
 * subordinate to Balance sitting next to it.
 *
 * There is no negative-balance tint here: 5a carries that entirely on the
 * warning icon `CustomerDrawerStats` puts beside the figure.
 */
export function CustomerDrawerStat({
  label,
  value,
  subLine,
  size = 'sm',
  muted = false,
}: {
  label: string;
  value: ReactNode;
  subLine?: string;
  size?: 'sm' | 'lg';
  muted?: boolean;
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</span>
      <span
        className={cn(
          'tabular-nums',
          size === 'lg' ? 'text-2xl font-semibold' : 'pt-1 text-sm font-medium',
          muted && 'text-muted-foreground',
        )}
      >
        {value}
      </span>
      {subLine && <span className="text-muted-foreground pt-0.5 text-xs">{subLine}</span>}
    </div>
  );
}
