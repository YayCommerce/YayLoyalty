import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * One stat cell: label, value and an optional sub-line. Callers pass formatted figures, never abbreviated ones.
 * `size="lg"` is for the two headline figures. The smaller size is padded so its baseline sits under the taller row.
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
