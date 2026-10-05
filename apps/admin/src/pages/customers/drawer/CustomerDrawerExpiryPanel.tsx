import { TriangleAlert } from 'lucide-react';

import { formatStoredDate, parseWPDate, utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
import type { Customer, DrawerExpiry } from '@/lib/schema/customer.schema';
import { Button } from '@/components/ui/button';
import { ACTION_RESET_EXPIRY, NOT_AVAILABLE_YET_REASON } from './customer-drawer.copy';
import { daysUntil } from './customer-drawer.money';
import {
  ACTION_EXEMPT_FROM_EXPIRY,
  ACTION_SEND_REMINDER_NOW,
  expiringBannerBody,
  expiringBannerTitle,
  expiringReminderSentence,
} from './customer-drawer.panels.copy';

/** The expiry warning and three disabled actions, which share the main row's "not available yet" reason. */
export function CustomerDrawerExpiryPanel({ customer, expiry }: { customer: Customer; expiry: DrawerExpiry }) {
  const expiresAt = utcToDate(expiry.expires_at);
  const daysRemaining = daysUntil(expiry.expires_at);
  // Null, not "0%", when the balance is not positive: zero would read as reassurance.
  const sharePercent =
    customer.balance > 0 ? String(Math.round((expiry.expiring_points / customer.balance) * 100)) : null;
  const money = formatPointsAsMoney(expiry.expiring_points, customer.points_per_currency_unit);

  return (
    <div className="bg-muted/50 flex flex-col gap-3 rounded-md border px-3.5 py-3">
      <div className="flex items-start gap-2">
        <TriangleAlert className="text-muted-foreground mt-0.5 size-4 shrink-0" />
        <div className="flex flex-col gap-1">
          <p className="text-sm font-medium">
            {expiringBannerTitle(
              formatPoints(expiry.expiring_points),
              formatPoints(customer.balance),
              parseWPDate(expiresAt),
            )}
          </p>
          <p className="text-muted-foreground text-xs">
            {expiringBannerBody(daysRemaining, money ?? formatPoints(expiry.expiring_points), sharePercent)}
          </p>
          {/* Needs both dates: falling back to `expires_at` for a missing `sent_at` would state a wrong date. */}
          {expiry.reminders.sent_at && expiry.reminders.next_due_at && (
            <p className="text-muted-foreground text-xs">
              {expiringReminderSentence(
                formatStoredDate(expiry.reminders.sent_at),
                formatStoredDate(expiry.reminders.next_due_at),
                String(expiry.reminders.window_days),
              )}
            </p>
          )}
        </div>
      </div>
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <Button variant="outline" size="sm" disabled>
          {ACTION_RESET_EXPIRY()}
        </Button>
        <Button variant="outline" size="sm" disabled>
          {ACTION_EXEMPT_FROM_EXPIRY()}
        </Button>
        <Button variant="outline" size="sm" disabled>
          {ACTION_SEND_REMINDER_NOW()}
        </Button>
      </div>
      <p className="text-muted-foreground text-xs">{NOT_AVAILABLE_YET_REASON()}</p>
    </div>
  );
}
