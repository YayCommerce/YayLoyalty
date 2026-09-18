import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * One stat cell: label above value, optional sub-line beneath. Pure
 * presentation — callers pass already-formatted strings (`formatPoints`,
 * never `formatCompactPoints`; figures are never abbreviated in this drawer).
 * `tone="warning"` is the negative-balance treatment: a tint, never red —
 * mirrors `BalanceCell` on the Customers list.
 */
export function CustomerDrawerStat({
  label,
  value,
  subLine,
  tone = 'default',
}: {
  label: string;
  value: ReactNode;
  subLine?: string;
  tone?: 'default' | 'warning';
}) {
  return (
    <div
      className={cn(
        'flex flex-col gap-1 rounded-lg border p-3',
        tone === 'warning' && 'bg-muted/40 border-l-muted-foreground/40 border-l-[3px]',
      )}
    >
      <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</span>
      <span className="text-lg font-semibold tabular-nums">{value}</span>
      {subLine && <span className="text-muted-foreground text-xs">{subLine}</span>}
    </div>
  );
}
