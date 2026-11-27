import { formatPoints } from '@/lib/helpers/points.helper';
import {
  addsToLiability,
  BALANCE_LABEL,
  negativeOutcomeLiability,
  negativeOutcomeSpendable,
  NEW_BALANCE_EMPTY,
  reducesLiability,
  singleSummaryTitle,
} from '../customers-adjust-points.copy';
import {
  liabilityDelta,
  newBalance,
  pointsToClearDeficit,
  type AdjustFormState,
} from '../customers-adjust-points.form-state';
import { AdjustPointsBeforeAfter } from './AdjustPointsBeforeAfter';
import { AdjustPointsSummaryCard } from './AdjustPointsSummaryCard';

/**
 * The summary card, for a single customer only.
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

  const balanceAfter = newBalance(state, balance);
  const delta = liabilityDelta(balance, balanceAfter);
  const deltaPoints = formatPoints(Math.abs(delta));
  const signed = balanceAfter - balance;

  return (
    <AdjustPointsSummaryCard
      title={singleSummaryTitle(state.direction, customerName)}
      badge={state.amount > 0 ? `${signed > 0 ? '+' : ''}${formatPoints(signed)}` : undefined}
    >
      {state.amount === 0 ? (
        <p className="text-muted-foreground m-0 text-sm">{NEW_BALANCE_EMPTY()}</p>
      ) : (
        <>
          <AdjustPointsBeforeAfter caption={BALANCE_LABEL()} before={balance} after={balanceAfter} />
          {balanceAfter < 0 && (
            <p className="text-muted-foreground m-0 text-xs">
              {negativeOutcomeSpendable(customerName, formatPoints(pointsToClearDeficit(balanceAfter)))}
            </p>
          )}
          {delta !== 0 && (
            <p className="text-muted-foreground m-0 text-xs">
              {balanceAfter < 0
                ? negativeOutcomeLiability(deltaPoints)
                : delta > 0
                  ? addsToLiability(deltaPoints)
                  : reducesLiability(deltaPoints)}
            </p>
          )}
        </>
      )}
    </AdjustPointsSummaryCard>
  );
}
