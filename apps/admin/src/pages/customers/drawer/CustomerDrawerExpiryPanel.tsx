import { TriangleAlert } from 'lucide-react';

import { formatStoredDate, parseWPDate, utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer, DrawerExpiry } from '@/lib/schema/customer.schema';
import { Button } from '@/components/ui/button';
import { ACTION_RESET_EXPIRY, NOT_AVAILABLE_YET_REASON } from './customer-drawer.copy';
import { daysUntil, pointsToMoney } from './customer-drawer.money';
import {
  ACTION_EXEMPT_FROM_EXPIRY,
  ACTION_SEND_REMINDER_NOW,
  expiringBannerBody,
  expiringBannerTitle,
  expiringReminderSentence,
} from './customer-drawer.panels.copy';

/**
 * 4b's warning banner + three disabled actions. Rendered only when
 * `customer.expiry` is non-null (always null today — no `lots` table on the
 * real path, docs/data-model.md).
 *
 * "Reset expiry" reuses the SAME reason `CustomerDrawerActions.tsx` shows for
 * it in the main actions row (`NOT_AVAILABLE_YET_REASON`) — the plan calls
 * for staying consistent with that component rather than inventing a second
 * reason for one button.
 * Exempt/Send reminder are new actions with no endpoint anywhere yet, so
 * they get the same generic reason.
 */
export function CustomerDrawerExpiryPanel({ customer, expiry }: { customer: Customer; expiry: DrawerExpiry }) {
  const expiresAt = utcToDate(expiry.expires_at);
  const daysRemaining = daysUntil(expiry.expires_at);
  // `customer.balance` guarded against zero/negative — a customer can carry
  // a negative balance, and this math must never divide by it even though
  // `expiry` itself is null there today (defensive, not reachable yet). `null`
  // (not a fabricated "0%") when it can't be honestly computed — printing
  // zero would read as reassurance for what could be a majority of a
  // non-positive balance.
  const sharePercent =
    customer.balance > 0 ? String(Math.round((expiry.expiring_points / customer.balance) * 100)) : null;
  const money = pointsToMoney(expiry.expiring_points, customer.points_per_currency_unit);

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
          {/* Both dates are required to state the schedule honestly — a real
              `lots`-backed path could one day send `sent_at: null` ("not sent
              yet"), and falling back to `expires_at` would print "the
              reminder went out on the day the points expired", which is
              wrong, not just imprecise. Skip the sentence rather than guess. */}
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
