import { utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
import type { Customer, DrawerExpiry } from '@/lib/schema/customer.schema';
import { CustomerDrawerStat } from './CustomerDrawerStat';
import { daysUntil } from './customer-drawer.money';
import { expiringInDaysLabel, safeBeyondLabel } from './customer-drawer.panels.copy';

/** The expiry panel's two stats: Expiring in N days and Safe beyond {year}. Balance and Pending sit in the main stats. */
export function CustomerDrawerExpiryStats({ customer, expiry }: { customer: Customer; expiry: DrawerExpiry }) {
  const daysRemaining = daysUntil(expiry.expires_at);
  const safeBeyondYear = String(utcToDate(expiry.expires_at).getUTCFullYear());
  const rate = customer.points_per_currency_unit;

  return (
    <div className="grid grid-cols-2 gap-4">
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
    </div>
  );
}
