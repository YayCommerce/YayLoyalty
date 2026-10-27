import { useState } from 'react';
import { Calculator } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { storeCurrencySymbol } from '@/lib/helpers/format.helper';
import { ROUNDING, RoundingMode } from '@/lib/points-math/points-math';
import type { PurchaseConfig } from '@/lib/schema/campaign.schema';
import { Button } from '@/components/ui/button';
import { AMOUNT_INPUT_WIDTH } from '@/components/ui/custom/amount-inputs';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { PurchaseRateCalculatorDialog } from './PurchaseRateCalculatorDialog';

type PurchaseBase = NonNullable<PurchaseConfig['base']>;

/** Decimals the rate input keeps: enough for 1 pt per 1,000,000 of a 0-decimal currency. */
const RATE_DECIMALS = 6;

/** Points per 1 currency unit, typed directly or worked out in the calculator dialog. Shared by the built-in card and the Pro editor. */
export function PurchaseRateInput({
  id,
  value,
  onValueChange,
}: {
  id?: string;
  value: number;
  onValueChange: (value: number) => void;
}) {
  const [calculatorOpen, setCalculatorOpen] = useState(false);

  return (
    <div className="flex flex-wrap items-center gap-2">
      <NumberInputRoot
        size="small"
        className={AMOUNT_INPUT_WIDTH}
        min={10 ** -RATE_DECIMALS}
        decimalScale={RATE_DECIMALS}
        value={value}
        onValueChange={onValueChange}
      >
        <NumberInputInput id={id} />
        <NumberInputUnit
          unit={sprintf(
            /* translators: %s: the store currency symbol, e.g. "₫". */
            __('pts / %s', 'yayloyalty'),
            storeCurrencySymbol(),
          )}
        />
      </NumberInputRoot>
      <Button type="button" variant="link" size="sm" onClick={() => setCalculatorOpen(true)}>
        <Calculator />
        {__('Calculate from spend', 'yayloyalty')}
      </Button>

      <PurchaseRateCalculatorDialog
        open={calculatorOpen}
        onOpenChange={setCalculatorOpen}
        rate={value || 1}
        onApply={(rate) => {
          onValueChange(rate);
          setCalculatorOpen(false);
        }}
      />
    </div>
  );
}

/** A purchase campaign's base amount: before or after discounts. Shared by the built-in card and the Pro editor. */
export function BaseAmountSegmented({
  value,
  onValueChange,
}: {
  value: PurchaseBase;
  onValueChange: (value: PurchaseBase) => void;
}) {
  return (
    <Segmented value={value} onValueChange={(next) => next && onValueChange(next as PurchaseBase)}>
      <SegmentedItem value="after_discounts">{__('After discounts', 'yayloyalty')}</SegmentedItem>
      <SegmentedItem value="before_discounts">{__('Before discounts', 'yayloyalty')}</SegmentedItem>
    </Segmented>
  );
}

/** How a purchase campaign turns its result into whole points. */
export function RoundingSegmented({
  value,
  onValueChange,
}: {
  value: RoundingMode;
  onValueChange: (value: RoundingMode) => void;
}) {
  return (
    <Segmented value={value} onValueChange={(next) => next && onValueChange(next as RoundingMode)}>
      <SegmentedItem value={ROUNDING.DOWN}>{__('Down', 'yayloyalty')}</SegmentedItem>
      <SegmentedItem value={ROUNDING.NEAREST}>{__('Nearest', 'yayloyalty')}</SegmentedItem>
      <SegmentedItem value={ROUNDING.UP}>{__('Up', 'yayloyalty')}</SegmentedItem>
    </Segmented>
  );
}
