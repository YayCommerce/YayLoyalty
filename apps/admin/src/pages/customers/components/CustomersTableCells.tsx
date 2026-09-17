import { TriangleAlert } from 'lucide-react';

import { cn } from '@/lib/utils';
import { PointsValue } from '@/components/ui/custom/points-value';

/** Two-line Customer cell: name (plain text for now — no customer drawer exists yet) + muted email. */
export function CustomerNameCell({ name, email }: { name: string; email: string }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5">
      <span className="truncate text-sm">{name}</span>
      <span className="text-muted-foreground truncate text-xs">{email}</span>
    </div>
  );
}

/**
 * Balance cell. Negative → warning glyph + tinted, never `destructive` (the
 * design is explicit: "tinted and marked, not colored red"). `stale` renders
 * the same value muted/non-bold — for the balance-rebuild toggle, where the
 * figure is real but frozen at the snapshot time (see CustomersRebuildBanner.tsx).
 */
export function BalanceCell({ value, stale }: { value: number; stale?: boolean }) {
  const negative = value < 0;

  return (
    <span
      className={cn(
        'flex items-center justify-end gap-1.5 tabular-nums',
        stale ? 'text-muted-foreground font-normal' : 'font-semibold',
      )}
    >
      {negative && <TriangleAlert className="text-muted-foreground size-3.5" />}
      <PointsValue value={value} />
    </span>
  );
}
