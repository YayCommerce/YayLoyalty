import { TriangleAlert } from 'lucide-react';

import { parseWPDate, utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import { CustomerDrawerStat } from './CustomerDrawerStat';
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

/**
 * Five stats on the `auto-fit / minmax(150px,1fr)` grid — three columns at the
 * drawer's 600px width, which is what 1e/1f draw, and a graceful reflow at the
 * 782px full-screen size and for six-figure figures (3c's fix). Takes
 * `customer` and nothing else: every figure is derived here, so swapping the
 * fixture for the real endpoint later changes no props.
 *
 * Balance and Pending are the two headline figures (`size="lg"`); Expires and
 * the two lifetime totals are secondary. `Worth` is deliberately NOT shown —
 * 3c drew a sixth Worth stat but 1e, the baseline this drawer follows, does
 * not, and the endpoint's `worth` field stays on the wire either way.
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
        value={customer.expires_at && !isDeleted ? parseWPDate(utcToDate(customer.expires_at)) : '—'}
        subLine={isDeleted ? EXPIRY_STOPPED_SUBLINE() : undefined}
      />
      <CustomerDrawerStat label={STAT_LIFETIME_EARNED()} value={formatPoints(customer.lifetime_earned)} />
      <CustomerDrawerStat label={STAT_LIFETIME_REDEEMED()} value={formatPoints(customer.lifetime_redeemed)} />
    </div>
  );
}
