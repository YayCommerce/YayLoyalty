import { TriangleAlert } from 'lucide-react';
import { Link } from 'react-router';

import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { PointsValue } from '@/components/ui/custom/points-value';

/**
 * Two-line Customer cell linking to the drawer. `search` is the list's current query string, carried into the drawer URL.
 * `state.row` lets the drawer render identity before its own query resolves.
 */
export function CustomerNameCell({ customer, search }: { customer: CustomerListRow; search: string }) {
  return (
    <Link
      to={customerDrawerPath(customer, search)}
      state={{ row: customer }}
      className="text-foreground flex min-w-0 items-center gap-3"
    >
      <Avatar>
        <AvatarFallback className="bg-chart-1/10 text-chart-1 text-xs font-semibold">
          {(customer.name.trim()[0] ?? '?').toUpperCase()}
        </AvatarFallback>
      </Avatar>
      <span className="flex min-w-0 flex-col gap-0.5">
        <span className="truncate text-sm font-medium">{customer.name}</span>
        <span className="text-muted-foreground truncate text-xs">{customer.email}</span>
      </span>
    </Link>
  );
}

/** The drawer URL for a customer, keeping the list's query string. */
export function customerDrawerPath(customer: CustomerListRow, search: string): string {
  return `/customers/${customer.user_id}${search ? `?${search}` : ''}`;
}

/** A negative balance gets a warning glyph, never the `destructive` red. */
export function BalanceCell({ value }: { value: number }) {
  const negative = value < 0;

  return (
    <span className="flex items-center justify-center gap-1.5 tabular-nums">
      {negative && <TriangleAlert className="text-muted-foreground size-3.5" />}
      <PointsValue value={value} />
    </span>
  );
}
