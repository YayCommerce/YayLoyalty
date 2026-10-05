import { TriangleAlert } from 'lucide-react';
import { Link } from 'react-router';

import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { cn } from '@/lib/utils';
import { PointsValue } from '@/components/ui/custom/points-value';

/**
 * Two-line Customer cell linking to the drawer. `search` is the list's current query string, carried into the drawer URL.
 * `state.row` lets the drawer render identity before its own query resolves.
 */
export function CustomerNameCell({ customer, search }: { customer: CustomerListRow; search: string }) {
  return (
    <Link
      to={`/customers/${customer.user_id}${search ? `?${search}` : ''}`}
      state={{ row: customer }}
      className="flex min-w-0 flex-col gap-0.5"
    >
      <span className="truncate text-sm">{customer.name}</span>
      <span className="text-muted-foreground truncate text-xs">{customer.email}</span>
    </Link>
  );
}

/**
 * A negative balance gets a warning glyph, never the `destructive` red.
 * `stale` mutes the value during a balance rebuild, when the figure is real but frozen.
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
