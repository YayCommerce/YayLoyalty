import { formatPoints } from '@/lib/helpers/points.helper';
import {
  addsToLiability,
  negativeOutcomeLiability,
  negativeOutcomeSpendable,
  NEW_BALANCE_EMPTY,
  newBalanceLine,
  reducesLiability,
} from '../customers-adjust-points.copy';
import {
  liabilityDelta,
  newBalance,
  pointsToClearDeficit,
  type AdjustFormState,
} from '../customers-adjust-points.form-state';

/**
 * The result panel, for a single customer only.
 * `showResultPanel` is false while an already-negative balance stays negative:
 * AdjustPointsNegativeBalancePanel shows those figures instead.
 */
export function AdjustPointsFields({
  state,
  customerName,
  balance,
  showResultPanel,
}: {
  state: Pick<AdjustFormState, 'direction' | 'amount'>;
  customerName: string;
  balance: number;
  showResultPanel: boolean;
}) {
  if (!showResultPanel) {
    return null;
  }

  if (state.amount === 0) {
    return <p className="text-muted-foreground text-sm">{NEW_BALANCE_EMPTY()}</p>;
  }

  const balanceAfter = newBalance(state, balance);
  const delta = liabilityDelta(balance, balanceAfter);
  const deltaPoints = formatPoints(Math.abs(delta));

  if (balanceAfter < 0) {
    return (
      <div className="bg-muted/50 flex flex-col gap-1.5 rounded-md border px-3.5 py-3 text-xs">
        <p className="text-sm font-medium">{newBalanceLine(formatPoints(balanceAfter))}</p>
        <p className="text-muted-foreground">
          {negativeOutcomeSpendable(customerName, formatPoints(pointsToClearDeficit(balanceAfter)))}
        </p>
        {delta < 0 && <p className="text-muted-foreground">{negativeOutcomeLiability(deltaPoints)}</p>}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-1">
      <p className="text-sm font-medium">{newBalanceLine(formatPoints(balanceAfter))}</p>
      {delta !== 0 && (
        <p className="text-muted-foreground text-xs">
          {delta > 0 ? addsToLiability(deltaPoints) : reducesLiability(deltaPoints)}
        </p>
      )}
    </div>
  );
}
