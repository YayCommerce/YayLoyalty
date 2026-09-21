import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { AMOUNT_LABEL, DIRECTION_ADD_LABEL, DIRECTION_REMOVE_LABEL, POINTS_UNIT } from '../customers-adjust-points.copy';
import type { AdjustDirection, AdjustFormState } from '../customers-adjust-points.form-state';

/** Direction toggle + magnitude input. Reused by both the single and bulk layouts (bulk swaps `label`). */
export function AdjustPointsAmountField({
  state,
  onChange,
  label,
}: {
  state: Pick<AdjustFormState, 'direction' | 'amount'>;
  onChange: (patch: Partial<AdjustFormState>) => void;
  label?: string;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Segmented
        shape="square"
        aria-label={label ?? AMOUNT_LABEL()}
        value={state.direction}
        onValueChange={(value) => value && onChange({ direction: value as AdjustDirection })}
        className="w-max"
      >
        <SegmentedItem value="add">{DIRECTION_ADD_LABEL()}</SegmentedItem>
        <SegmentedItem value="remove">{DIRECTION_REMOVE_LABEL()}</SegmentedItem>
      </Segmented>

      <NumberInputRoot min={0} value={state.amount} onValueChange={(amount) => onChange({ amount })}>
        <NumberInputInput aria-label={label ?? AMOUNT_LABEL()} />
        <NumberInputUnit unit={POINTS_UNIT()} />
      </NumberInputRoot>
    </div>
  );
}
