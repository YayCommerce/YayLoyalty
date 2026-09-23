import { formatPoints } from '@/lib/helpers/points.helper';
import {
  AFTER_ADJUSTMENT_TITLE,
  needsMoreBeforeSpendable,
  NEGATIVE_BALANCE_BODY,
  NEGATIVE_BALANCE_TITLE,
  NEVER_SHOWN_NEGATIVE,
  stillBelowZeroBadge,
} from '../customers-adjust-points.copy';
import { newBalance, pointsToClearDeficit, type AdjustFormState } from '../customers-adjust-points.form-state';

/**
 * The customer's balance is already below zero, independent of what this
 * adjustment does. Distinct from `AdjustPointsFields`'s negative-outcome
 * panel ("this adjustment would go negative"): this one is "it
 * already is", and stays visible above the fields even before an amount is
 * typed. Its own "after this adjustment" figures only show once an amount
 * is typed (`amount > 0`) and only while the balance would still be
 * negative — once it would cross back to zero or above, `AdjustPointsFields`'s
 * normal result panel takes over instead (see `showResultPanel` there).
 */
export function AdjustPointsNegativeBalancePanel({
  state,
  customerName,
  balance,
}: {
  state: Pick<AdjustFormState, 'direction' | 'amount'>;
  customerName: string;
  balance: number;
}) {
  const balanceAfter = newBalance(state, balance);
  const stillNegative = state.amount > 0 && balanceAfter < 0;

  return (
    <div className="bg-muted/50 flex flex-col gap-2 rounded-md border px-3.5 py-3 text-xs">
      <p className="text-sm font-medium">{NEGATIVE_BALANCE_TITLE()}</p>
      <p className="text-muted-foreground">{NEGATIVE_BALANCE_BODY()}</p>

      {stillNegative && (
        <div className="flex flex-col gap-1 border-t pt-2">
          <p className="text-xs font-medium">
            {AFTER_ADJUSTMENT_TITLE()} · {stillBelowZeroBadge()}
          </p>
          <p className="text-muted-foreground">
            {needsMoreBeforeSpendable(
              customerName,
              formatPoints(pointsToClearDeficit(balanceAfter)),
              formatPoints(pointsToClearDeficit(balance)),
            )}
          </p>
          <p className="text-muted-foreground">{NEVER_SHOWN_NEGATIVE()}</p>
        </div>
      )}
    </div>
  );
}
