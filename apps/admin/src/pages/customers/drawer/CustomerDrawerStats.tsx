import { TriangleAlert } from 'lucide-react';

import { parseWPCurrency, parseWPDate, utcToDate } from '@/lib/helpers/format.helper';
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
  STAT_WORTH,
} from './customer-drawer.copy';

/**
 * Six stats on the `auto-fit / minmax(150px,1fr)` grid (3c) — `Worth` is
 * what forces that grid; a fixed five/six-column layout has no room for it
 * once figures grow to six digits. Takes `customer` and nothing else: every
 * figure is derived here, so swapping the fixture for the real endpoint
 * later changes no props.
 */
export function CustomerDrawerStats({ customer }: { customer: Customer }) {
  const isDeleted = !!customer.deleted_at;
  const negative = customer.balance < 0;

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-4">
      <CustomerDrawerStat
        label={isDeleted ? BALANCE_AT_DELETION_LABEL() : STAT_BALANCE()}
        value={
          negative ? (
            <span className="flex items-center gap-1.5">
              <TriangleAlert className="text-muted-foreground size-3.5" />
              {formatPoints(customer.balance)}
            </span>
          ) : (
            formatPoints(customer.balance)
          )
        }
        subLine={isDeleted ? BALANCE_AT_DELETION_SUBLINE() : undefined}
        tone={negative ? 'warning' : 'default'}
      />
      <CustomerDrawerStat
        label={STAT_PENDING()}
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
      {/* No conversion rate configured — omit the stat entirely rather than print a zero. */}
      {customer.worth.currency_amount !== null && (
        <CustomerDrawerStat label={STAT_WORTH()} value={parseWPCurrency(customer.worth.currency_amount)} />
      )}
    </div>
  );
}
