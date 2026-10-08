import { TriangleAlert } from 'lucide-react';

import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
import { Checkbox } from '@/components/ui/checkbox';
import {
  addsToLiability,
  negativeOutcomeLiability,
  negativeOutcomeSpendable,
  NEW_BALANCE_EMPTY,
  newBalanceLine,
  reducesLiability,
  typoGuardConfirm,
  typoGuardWarning,
} from '../customers-adjust-points.copy';
import {
  isTypoGuardActive,
  liabilityDelta,
  newBalance,
  pointsToClearDeficit,
  type AdjustFormState,
} from '../customers-adjust-points.form-state';

/**
 * The result panel and the typo guard, for a single customer only.
 * `showResultPanel` is false while an already-negative balance stays negative:
 * AdjustPointsNegativeBalancePanel shows those figures instead. The typo guard ignores that flag.
 */
export function AdjustPointsFields({
  state,
  onChange,
  customerName,
  balance,
  lifetimeEarned,
  pointsPerCurrencyUnit,
  showResultPanel,
}: {
  state: Pick<AdjustFormState, 'direction' | 'amount' | 'confirmed'>;
  onChange: (patch: Partial<AdjustFormState>) => void;
  customerName: string;
  balance: number;
  lifetimeEarned: number;
  pointsPerCurrencyUnit: number;
  showResultPanel: boolean;
}) {
  const guardActive = state.amount > 0 && isTypoGuardActive(state, lifetimeEarned, pointsPerCurrencyUnit);

  if (state.amount === 0) {
    return showResultPanel ? <p className="text-muted-foreground text-sm">{NEW_BALANCE_EMPTY()}</p> : null;
  }

  const balanceAfter = newBalance(state, balance);
  const balanceAfterMoney = formatPointsAsMoney(balanceAfter, pointsPerCurrencyUnit);
  const isNegativeOutcome = balanceAfter < 0;
  const delta = liabilityDelta(balance, balanceAfter);
  const deltaMoney = formatPointsAsMoney(Math.abs(delta), pointsPerCurrencyUnit);

  return (
    <div className="flex flex-col gap-3">
      {showResultPanel &&
        (isNegativeOutcome ? (
          <div className="bg-muted/50 flex flex-col gap-1.5 rounded-md border px-3.5 py-3 text-xs">
            <p className="text-sm font-medium">{newBalanceLine(formatPoints(balanceAfter), balanceAfterMoney)}</p>
            <p className="text-muted-foreground">
              {negativeOutcomeSpendable(customerName, formatPoints(pointsToClearDeficit(balanceAfter)))}
            </p>
            {delta < 0 && deltaMoney && <p className="text-muted-foreground">{negativeOutcomeLiability(deltaMoney)}</p>}
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
        <div className="border-warning bg-warning/6 flex flex-col gap-2.5 rounded-md border px-3.5 py-3">
          <p className="flex items-start gap-2 text-sm font-medium">
            <TriangleAlert className="text-warning-accent mt-0.5 size-4 shrink-0" />
            {typoGuardWarning(formatPoints(state.amount), formatPoints(lifetimeEarned))}
          </p>
          <label className="flex items-start gap-2.5 text-sm">
            <Checkbox
              checked={state.confirmed}
              onCheckedChange={(checked) => onChange({ confirmed: checked === true })}
            />
            <span>{typoGuardConfirm(state.direction, formatPoints(state.amount))}</span>
          </label>
        </div>
      )}
    </div>
  );
}
