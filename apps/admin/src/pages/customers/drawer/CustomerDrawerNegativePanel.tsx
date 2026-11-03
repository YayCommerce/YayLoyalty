import { formatStoredDate, formatWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { DrawerNegativeBalance } from '@/lib/schema/customer.schema';
import {
  NEGATIVE_BALANCE_HEADING,
  NEGATIVE_WHAT_CUSTOMER_SEES_BODY,
  NEGATIVE_WHAT_HAPPENS_NEXT_TITLE,
  negativeBalanceFactEarned,
  negativeBalanceFactReversed,
  negativeBalanceFactSpent,
  negativeBalanceGenericExplanation,
  negativeWhatCustomerSeesTitle,
  negativeWhatHappensNextBody,
} from './customer-drawer.panels.copy';

/**
 * Why the balance is negative: three numbered facts for a refund reversal, or one generic sentence otherwise.
 * An adjustment or an expiry can also push a balance below zero, so the facts never assume all three legs exist.
 */
export function CustomerDrawerNegativePanel({
  customerFirstName,
  negativeBalance,
}: {
  customerFirstName: string;
  negativeBalance: DrawerNegativeBalance;
}) {
  const { shape, earned, spent, reversed, deficit } = negativeBalance;
  const canTellStory = 'refund_reversal' === shape && earned && spent && reversed;

  return (
    <div className="bg-muted/50 flex flex-col gap-3 rounded-md border px-3.5 py-3">
      <p className="text-sm font-medium">{NEGATIVE_BALANCE_HEADING()}</p>

      {canTellStory ? (
        <ol className="text-muted-foreground flex list-decimal flex-col gap-1.5 pl-4 text-xs">
          <li>
            {negativeBalanceFactEarned(
              customerFirstName,
              formatPoints(earned.points),
              String(earned.order_id),
              formatStoredDate(earned.occurred_at),
            )}
          </li>
          <li>
            {negativeBalanceFactSpent(
              formatPoints(spent.points),
              String(spent.order_id),
              formatWPCurrency(spent.value),
            )}
          </li>
          <li>{negativeBalanceFactReversed(String(reversed.order_id), formatPoints(reversed.points))}</li>
        </ol>
      ) : (
        <p className="text-muted-foreground text-xs">
          {negativeBalanceGenericExplanation(customerFirstName, formatPoints(deficit))}
        </p>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <p className="text-xs font-medium">{negativeWhatCustomerSeesTitle(customerFirstName)}</p>
          <p className="text-muted-foreground text-xs">{NEGATIVE_WHAT_CUSTOMER_SEES_BODY()}</p>
        </div>
        <div className="flex flex-col gap-1">
          <p className="text-xs font-medium">{NEGATIVE_WHAT_HAPPENS_NEXT_TITLE()}</p>
          <p className="text-muted-foreground text-xs">{negativeWhatHappensNextBody(formatPoints(deficit))}</p>
        </div>
      </div>
    </div>
  );
}
