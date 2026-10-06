import { TriangleAlert } from 'lucide-react';
import { Link } from 'react-router';

import type { CustomerListRow } from '@/lib/schema/customer.schema';
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

/** A negative balance gets a warning glyph, never the `destructive` red. */
export function BalanceCell({ value }: { value: number }) {
  const negative = value < 0;

  return (
    <span className="flex items-center justify-end gap-1.5 font-semibold tabular-nums">
      {negative && <TriangleAlert className="text-muted-foreground size-3.5" />}
      <PointsValue value={value} />
    </span>
  );
}
