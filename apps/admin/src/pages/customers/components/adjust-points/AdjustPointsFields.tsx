import { formatPoints } from '@/lib/helpers/points.helper';
import { NumberInputInput, NumberInputRoot } from '@/components/ui/number-input';
import { pointsToMoney } from '../../drawer/customer-drawer.money';
import {
  addsToLiability,
  CONFIRM_AMOUNT_LABEL,
  negativeOutcomeLiability,
  negativeOutcomeSpendable,
  newBalanceLine,
  NEW_BALANCE_EMPTY,
  reducesLiability,
  TYPO_GUARD_EXPLANATION,
  typoGuardNewBalance,
  typoGuardPlaceholder,
  typoGuardWarning,
} from '../customers-adjust-points.copy';
import {
  type AdjustFormState,
  isTypoGuardActive,
  liabilityDelta,
  newBalance,
  pointsToClearDeficit,
} from '../customers-adjust-points.form-state';

/**
 * Result panel (2b normal / 2c negative outcome / empty) + 2d typo guard.
 * Single-customer only — bulk (phase 05) has no per-customer record to
 * compute a result panel against, and reuses only `AdjustPointsAmountField`.
 *
 * `showResultPanel` is false while the customer's *starting* balance is
 * already negative and this adjustment keeps it negative — `3a`'s own panel
 * (`AdjustPointsNegativeBalancePanel`) covers that story instead, so the two
 * don't render duplicate "after this adjustment" figures. The typo guard is
 * independent of that flag: a mistyped amount can happen regardless of
 * which starting-balance story is showing.
 */
export function AdjustPointsFields({
  state,
  onChange,
  customerName,
  balance,
  lifetimeEarned,
  pointsPerCurrencyUnit,
  showResultPanel = true,
}: {
  state: Pick<AdjustFormState, 'direction' | 'amount' | 'confirmAmount'>;
  onChange: (patch: Partial<AdjustFormState>) => void;
  customerName: string;
  balance: number;
  lifetimeEarned: number;
  pointsPerCurrencyUnit: number;
  showResultPanel?: boolean;
}) {
  const guardActive = state.amount > 0 && isTypoGuardActive(state, lifetimeEarned, pointsPerCurrencyUnit);

  if (state.amount === 0) {
    return showResultPanel ? <p className="text-muted-foreground text-sm">{NEW_BALANCE_EMPTY()}</p> : null;
  }

  const balanceAfter = newBalance(state, balance);
  const balanceAfterMoney = pointsToMoney(balanceAfter, pointsPerCurrencyUnit);
  const isNegativeOutcome = balanceAfter < 0;
  // Only the portion of a balance above 0 ever counts as liability
  // (`liability_points()` sums `balance > 0`), so this is never the raw
  // signed amount once either side of the adjustment is negative — see
  // `liabilityDelta()`'s docblock.
  const delta = liabilityDelta(balance, balanceAfter);
  const deltaMoney = pointsToMoney(Math.abs(delta), pointsPerCurrencyUnit);

  return (
    <div className="flex flex-col gap-3">
      {showResultPanel &&
        (isNegativeOutcome ? (
          <div className="bg-muted/50 flex flex-col gap-1.5 rounded-md border px-3.5 py-3 text-xs">
            <p className="text-sm font-medium">{newBalanceLine(formatPoints(balanceAfter), balanceAfterMoney)}</p>
            <p className="text-muted-foreground">
              {negativeOutcomeSpendable(customerName, formatPoints(pointsToClearDeficit(balanceAfter)))}
            </p>
            {delta < 0 && deltaMoney && (
              <p className="text-muted-foreground">{negativeOutcomeLiability(deltaMoney)}</p>
            )}
          </div>
        ) : (
          <div className="flex flex-col gap-1">
            <p className="text-sm font-medium">{newBalanceLine(formatPoints(balanceAfter), balanceAfterMoney)}</p>
            {delta !== 0 && deltaMoney && (
              <p className="text-muted-foreground text-xs">
                {delta > 0 ? addsToLiability(deltaMoney) : reducesLiability(deltaMoney)}
              </p>
            )}
          </div>
        ))}

      {guardActive && (
        <div className="flex flex-col gap-2 rounded-md border border-dashed px-3.5 py-3">
          <p className="text-sm font-medium">
            {typoGuardWarning(formatPoints(state.amount), formatPoints(lifetimeEarned))}
          </p>
          <p className="text-muted-foreground text-xs">{TYPO_GUARD_EXPLANATION()}</p>
          <NumberInputRoot min={0} value={state.confirmAmount} onValueChange={(confirmAmount) => onChange({ confirmAmount })}>
            <NumberInputInput
              aria-label={CONFIRM_AMOUNT_LABEL()}
              placeholder={typoGuardPlaceholder(formatPoints(state.amount))}
            />
          </NumberInputRoot>
          <p className="text-muted-foreground text-xs">
            {typoGuardNewBalance(formatPoints(balanceAfter), balanceAfterMoney)}
          </p>
        </div>
      )}
    </div>
  );
}
