import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * One stat cell: label, value and an optional sub-line. Callers pass formatted figures, never abbreviated ones.
 * `size="lg"` is for the two headline figures, the smaller size for the strip under them.
 */
export function CustomerDrawerStat({
  label,
  value,
  subLine,
  size = 'sm',
  muted = false,
  className,
}: {
  label: string;
  value: ReactNode;
  subLine?: ReactNode;
  size?: 'sm' | 'lg';
  muted?: boolean;
  className?: string;
}) {
  return (
    <div className={cn('flex min-w-0 flex-col gap-0.5', className)}>
      <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</span>
      <span
        className={cn(
          'tabular-nums',
          size === 'lg' ? 'text-2xl font-semibold' : 'text-sm font-medium',
          muted && 'text-muted-foreground',
        )}
      >
        {value}
      </span>
      {subLine && <span className="text-muted-foreground text-xs">{subLine}</span>}
    </div>
  );
}
