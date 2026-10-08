import { TriangleAlert } from 'lucide-react';

import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import {
  BALANCE_AT_DELETION_LABEL,
  BALANCE_AT_DELETION_SUBLINE,
  EXPIRY_STOPPED_SUBLINE,
  expiresInDays,
  PENDING_SUBLINE,
  STAT_BALANCE,
  STAT_EXPIRES,
  STAT_LIFETIME_EARNED,
  STAT_LIFETIME_REDEEMED,
  STAT_PENDING,
} from './customer-drawer.copy';
import { daysUntil } from './customer-drawer.money';
import { CustomerDrawerStat } from './CustomerDrawerStat';

/** The drawer's stats, all derived from `customer`: Balance and Pending on top, then Expires and the lifetime totals. */
export function CustomerDrawerStats({ customer }: { customer: Customer }) {
  const isDeleted = !!customer.deleted_at;
  const negative = customer.balance < 0;
  const expiresAt = isDeleted ? null : customer.expires_at;

  return (
    <div className="rounded-md border">
      <div className="grid grid-cols-2 gap-4 p-4">
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
      </div>
      <div className="grid grid-cols-3 divide-x border-t">
        <CustomerDrawerStat
          className="px-4 py-3"
          label={STAT_EXPIRES()}
          value={expiresAt ? formatStoredDate(expiresAt) : '—'}
          subLine={isDeleted ? EXPIRY_STOPPED_SUBLINE() : expiresAt ? expiresInDays(daysUntil(expiresAt)) : undefined}
        />
        <CustomerDrawerStat
          className="px-4 py-3"
          label={STAT_LIFETIME_EARNED()}
          value={formatPoints(customer.lifetime_earned)}
        />
        <CustomerDrawerStat
          className="px-4 py-3"
          label={STAT_LIFETIME_REDEEMED()}
          value={formatPoints(customer.lifetime_redeemed)}
        />
      </div>
    </div>
  );
}
