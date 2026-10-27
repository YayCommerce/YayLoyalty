import { __, _n } from '@wordpress/i18n';

import { decimalsFor } from '@/lib/helpers/format.helper';
import { cn } from '@/lib/utils';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';

/** Fits a long points or money value, such as 10,000,000 ₫, beside its unit. */
export const AMOUNT_INPUT_WIDTH = 'w-44';

/** A whole number of points, with a "point(s)" unit. */
export function PointsInput({
  value,
  min,
  onValueChange,
}: {
  value: number;
  min: number;
  onValueChange: (value: number) => void;
}) {
  return (
    <NumberInputRoot size="small" className={AMOUNT_INPUT_WIDTH} min={min} value={value} onValueChange={onValueChange}>
      <NumberInputInput />
      <NumberInputUnit unit={_n('point', 'points', value, 'yayloyalty')} />
    </NumberInputRoot>
  );
}

/** A non-negative money amount at the currency's decimals, with the currency code as its unit. */
export function MoneyInput({
  value,
  currency,
  onValueChange,
}: {
  value: number;
  currency: string;
  onValueChange: (value: number) => void;
}) {
  return (
    <NumberInputRoot
      size="small"
      className={AMOUNT_INPUT_WIDTH}
      min={0}
      decimalScale={decimalsFor(currency)}
      value={value}
      onValueChange={onValueChange}
    >
      <NumberInputInput />
      <NumberInputUnit unit={currency} />
    </NumberInputRoot>
  );
}

/** Placeholder for an empty optional limit. */
const noLimit = () => __('No limit', 'yayloyalty');

/** A money limit that may be left empty: empty is null ("no limit"), and an entered amount is above 0. */
export function OptionalMoneyInput({
  value,
  currency,
  placeholder,
  onValueChange,
}: {
  value: number | null;
  currency: string;
  placeholder?: string;
  onValueChange: (value: number | null) => void;
}) {
  return (
    <NumberInputRoot
      size="small"
      className={AMOUNT_INPUT_WIDTH}
      nullable
      min={0.01}
      decimalScale={decimalsFor(currency)}
      value={value}
      onValueChange={onValueChange}
    >
      <NumberInputInput placeholder={placeholder ?? noLimit()} />
      <NumberInputUnit unit={currency} />
    </NumberInputRoot>
  );
}

/** A whole-number limit that may be left empty: empty is null ("no limit"), and an entered count is at least 1. */
export function OptionalCountInput({
  id,
  value,
  unit,
  className,
  placeholder,
  onValueChange,
}: {
  id?: string;
  value: number | null;
  unit?: string;
  className?: string;
  placeholder?: string;
  onValueChange: (value: number | null) => void;
}) {
  return (
    <NumberInputRoot
      size="small"
      className={cn('w-28', className)}
      nullable
      min={1}
      value={value}
      onValueChange={onValueChange}
    >
      <NumberInputInput id={id} placeholder={placeholder ?? noLimit()} />
      {unit && <NumberInputUnit unit={unit} />}
    </NumberInputRoot>
  );
}
