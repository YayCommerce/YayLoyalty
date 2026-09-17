import { TriangleAlert } from 'lucide-react';
import { Link } from 'react-router';

import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { cn } from '@/lib/utils';
import { PointsValue } from '@/components/ui/custom/points-value';

/**
 * Two-line Customer cell: name + muted email, linking to the drawer.
 * `search` is the list page's own current query string (carries `?scenario=`
 * through, if present) — `CustomerTableRow.tsx` reads it once via
 * `useSearchParams()` and passes it down rather than each row re-reading it.
 * `state.row` lets `CustomerDrawer.tsx` render identity instantly, before
 * its own `useCustomerQuery` resolves.
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
