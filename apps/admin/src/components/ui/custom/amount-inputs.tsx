import { _n } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';

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
    <NumberInputRoot size="small" className="w-32" min={min} value={value} onValueChange={onValueChange}>
      <NumberInputInput />
      <NumberInputUnit unit={_n('point', 'points', value, 'yayloyalty')} />
    </NumberInputRoot>
  );
}

/** A non-negative money amount to two decimals, with the currency code as its unit. */
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
    <NumberInputRoot size="small" className="w-32" min={0} decimalScale={2} value={value} onValueChange={onValueChange}>
      <NumberInputInput />
      <NumberInputUnit unit={currency} />
    </NumberInputRoot>
  );
}

/**
 * 0 in an optional input means "none" and is written as null, so a stored 0
 * never reads as a real limit (e.g. Clamp blocks every redemption at a 0 cap).
 */
function zeroAsNone(next: number): number | null {
  return next > 0 ? next : null;
}

/** A money amount where 0 means none; see zeroAsNone(). */
export function OptionalMoneyInput({
  value,
  currency,
  onValueChange,
}: {
  value: number | null;
  currency: string;
  onValueChange: (value: number | null) => void;
}) {
  return (
    <MoneyInput value={value ?? 0} currency={currency} onValueChange={(next) => onValueChange(zeroAsNone(next))} />
  );
}

/** A whole-number limit where 0 means none; see zeroAsNone(). */
export function OptionalCountInput({
  value,
  unit,
  className,
  onValueChange,
}: {
  value: number | null;
  unit?: string;
  className?: string;
  onValueChange: (value: number | null) => void;
}) {
  return (
    <NumberInputRoot
      size="small"
      className={cn('w-28', className)}
      min={0}
      value={value ?? 0}
      onValueChange={(next) => onValueChange(zeroAsNone(next))}
    >
      <NumberInputInput />
      {unit && <NumberInputUnit unit={unit} />}
    </NumberInputRoot>
  );
}
