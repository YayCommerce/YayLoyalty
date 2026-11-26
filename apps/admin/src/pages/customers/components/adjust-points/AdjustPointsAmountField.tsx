import { Minus, Plus } from 'lucide-react';

import { Field, FieldLabel } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot } from '@/components/ui/number-input';
import { CHANGE_POINTS_LABEL, DECREASE_LABEL, INCREASE_LABEL } from '../customers-adjust-points.copy';
import { signedPoints, type AdjustFormState } from '../customers-adjust-points.form-state';

const STEP_BUTTON =
  'hover:bg-accent text-muted-foreground flex w-10 shrink-0 cursor-pointer items-center justify-center';

/** One signed stepper: a negative value removes points. Stored as direction + magnitude, so the rest of the form is unchanged. */
export function AdjustPointsAmountField({
  state,
  onChange,
}: {
  state: Pick<AdjustFormState, 'direction' | 'amount'>;
  onChange: (patch: Partial<AdjustFormState>) => void;
}) {
  const value = signedPoints(state);

  // Zero keeps the current direction, so stepping through it does not flip the summary's wording early.
  const setValue = (next: number) =>
    onChange(next === 0 ? { amount: 0 } : { direction: next < 0 ? 'remove' : 'add', amount: Math.abs(next) });

  return (
    <Field>
      <FieldLabel htmlFor="adjust-points-amount">{CHANGE_POINTS_LABEL()}</FieldLabel>
      <div className="flex h-9 w-56 items-stretch self-start overflow-hidden rounded-md border">
        <button
          type="button"
          className={`${STEP_BUTTON} border-e`}
          aria-label={DECREASE_LABEL()}
          onClick={() => setValue(value - 1)}
        >
          <Minus className="size-4" />
        </button>
        <NumberInputRoot
          value={value}
          onValueChange={setValue}
          className="h-full flex-1 rounded-none border-0 shadow-none"
        >
          <NumberInputInput id="adjust-points-amount" className="text-center" />
        </NumberInputRoot>
        <button
          type="button"
          className={`${STEP_BUTTON} border-s`}
          aria-label={INCREASE_LABEL()}
          onClick={() => setValue(value + 1)}
        >
          <Plus className="size-4" />
        </button>
      </div>
    </Field>
  );
}
