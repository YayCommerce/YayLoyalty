import { TriangleAlert } from 'lucide-react';

import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import {
  BALANCE_AT_DELETION_LABEL,
  BALANCE_AT_DELETION_SUBLINE,
  EXPIRY_STOPPED_SUBLINE,
  PENDING_SUBLINE,
  STAT_BALANCE,
  STAT_EXPIRES,
  STAT_LIFETIME_EARNED,
  STAT_LIFETIME_REDEEMED,
  STAT_PENDING,
} from './customer-drawer.copy';
import { CustomerDrawerStat } from './CustomerDrawerStat';

/**
 * Five stats on the `auto-fit / minmax(150px,1fr)` grid — three columns at the
 * drawer's 600px width, which is what 1e/1f draw, and a graceful reflow at the
 * 782px full-screen size and for six-figure figures (3c's fix). Takes
 * `customer` and nothing else: every figure is derived here.
 *
 * Balance and Pending are the two headline figures (`size="lg"`); Expires and
 * the two lifetime totals are secondary. `Worth` is deliberately NOT shown —
 * 3c drew a sixth Worth stat but 1e, the baseline this drawer follows, does
 * not.
 *
 * 5c's mobile artboard draws FOUR stats in a 2×2 grid (Balance, Pending,
 * Lifetime earned, Expires) — this component keeps all FIVE below `sm` too.
 * `auto-fit`/`minmax(150px,1fr)` already reflows to 2
 * columns at drawer-sheet mobile widths on its own, just with 5 cells
 * instead of 4 (a slightly taller grid, one cell wrapping to its own row).
 * Hiding a real figure to match an artboard's count would break 5c's own
 * rule (callout 5): "nothing is silently dropped."
 */
export function CustomerDrawerStats({ customer }: { customer: Customer }) {
  const isDeleted = !!customer.deleted_at;
  const negative = customer.balance < 0;

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-x-4 gap-y-3">
      <CustomerDrawerStat
        label={isDeleted ? BALANCE_AT_DELETION_LABEL() : STAT_BALANCE()}
        size="lg"
        value={
          negative ? (
            <span className="flex items-center gap-1.5">
              <TriangleAlert className="text-muted-foreground size-4" />
              {formatPoints(customer.balance)}
            </span>
          ) : (
            formatPoints(customer.balance)
          )
        }
        subLine={isDeleted ? BALANCE_AT_DELETION_SUBLINE() : undefined}
      />
      <CustomerDrawerStat
        label={STAT_PENDING()}
        size="lg"
        muted
        value={formatPoints(customer.pending_points)}
        subLine={PENDING_SUBLINE()}
      />
      <CustomerDrawerStat
        label={STAT_EXPIRES()}
        value={customer.expires_at && !isDeleted ? formatStoredDate(customer.expires_at) : '—'}
        subLine={isDeleted ? EXPIRY_STOPPED_SUBLINE() : undefined}
      />
      <CustomerDrawerStat label={STAT_LIFETIME_EARNED()} value={formatPoints(customer.lifetime_earned)} />
      <CustomerDrawerStat label={STAT_LIFETIME_REDEEMED()} value={formatPoints(customer.lifetime_redeemed)} />
    </div>
  );
}
