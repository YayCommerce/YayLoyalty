import { utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
import type { Customer, DrawerExpiry } from '@/lib/schema/customer.schema';
import { CustomerDrawerStat } from './CustomerDrawerStat';
import { STAT_BALANCE, STAT_PENDING } from './customer-drawer.copy';
import { daysUntil } from './customer-drawer.money';
import { expiringInDaysLabel, safeBeyondLabel } from './customer-drawer.panels.copy';

/**
 * 4b's four stats (Balance, Expiring in N days, Safe beyond {year}, Pending)
 * — reuses `CustomerDrawerStat`, the same cell the header grid uses, rather
 * than forking a second stat component for one more panel.
 */
export function CustomerDrawerExpiryStats({ customer, expiry }: { customer: Customer; expiry: DrawerExpiry }) {
  const daysRemaining = daysUntil(expiry.expires_at);
  const safeBeyondYear = String(utcToDate(expiry.expires_at).getUTCFullYear());
  const rate = customer.points_per_currency_unit;

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-x-4 gap-y-3">
      <CustomerDrawerStat
        label={STAT_BALANCE()}
        size="lg"
        value={formatPoints(customer.balance)}
        subLine={formatPointsAsMoney(customer.balance, rate) ?? undefined}
      />
      <CustomerDrawerStat
        label={expiringInDaysLabel(daysRemaining)}
        value={formatPoints(expiry.expiring_points)}
        subLine={formatPointsAsMoney(expiry.expiring_points, rate) ?? undefined}
      />
      <CustomerDrawerStat
        label={safeBeyondLabel(safeBeyondYear)}
        value={formatPoints(expiry.safe_points)}
        subLine={formatPointsAsMoney(expiry.safe_points, rate) ?? undefined}
      />
      <CustomerDrawerStat label={STAT_PENDING()} value={formatPoints(customer.pending_points)} />
    </div>
  );
}
