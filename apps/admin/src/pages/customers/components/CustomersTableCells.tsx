import { TriangleAlert } from 'lucide-react';

import { formatPoints } from '../customers-page.mock-data';

/** Two-line Customer cell: name (links to the drawer once it exists) + muted email. */
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
 * design is explicit: "tinted and marked, not colored red").
 */
export function BalanceCell({ value }: { value: number }) {
  const negative = value < 0;

  return (
    <span className="flex items-center justify-end gap-1.5 font-semibold tabular-nums">
      {negative && <TriangleAlert className="text-muted-foreground size-3.5" />}
      {formatPoints(value)}
    </span>
  );
}
