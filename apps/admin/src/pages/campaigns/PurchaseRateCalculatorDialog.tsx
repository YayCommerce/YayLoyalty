import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import {
  currencyDecimals,
  formatStoreDecimal,
  formatWPCurrency,
  storeCurrencySymbol,
} from '@/lib/helpers/format.helper';
import { rateFromSpend, ratePair } from '@/lib/helpers/points.helper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AMOUNT_INPUT_WIDTH } from '@/components/ui/custom/amount-inputs';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';

/** Works out the per-unit rate from "spend X to receive Y", for merchants who think in that pair. */
export function PurchaseRateCalculatorDialog({
  open,
  onOpenChange,
  rate,
  onApply,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The current rate, which seeds the pair each time the dialog opens. */
  rate: number;
  onApply: (rate: number) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {/* Mounted only while open, so each opening starts from the current rate. */}
        {open && <CalculatorBody rate={rate} onApply={onApply} onCancel={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function CalculatorBody({
  rate,
  onApply,
  onCancel,
}: {
  rate: number;
  onApply: (rate: number) => void;
  onCancel: () => void;
}) {
  const [pair, setPair] = useState(() => ratePair(rate));
  const result = pair.points > 0 ? rateFromSpend(pair.amount, pair.points) : null;

  return (
    <>
      <DialogHeader>
        <DialogTitle>{__('Calculate the rate', 'yayloyalty')}</DialogTitle>
        <DialogDescription>
          {__('Enter how much a customer spends and the points it earns.', 'yayloyalty')}
        </DialogDescription>
      </DialogHeader>

      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span>{__('Spend', 'yayloyalty')}</span>
        <NumberInputRoot
          size="small"
          className={AMOUNT_INPUT_WIDTH}
          min={10 ** -currencyDecimals()}
          decimalScale={currencyDecimals()}
          value={pair.amount}
          onValueChange={(amount) => setPair({ ...pair, amount })}
        >
          <NumberInputInput aria-label={__('Amount spent', 'yayloyalty')} />
          <NumberInputUnit unit={storeCurrencySymbol()} />
        </NumberInputRoot>
        <span>{__('to receive', 'yayloyalty')}</span>
        <NumberInputRoot
          size="small"
          className={AMOUNT_INPUT_WIDTH}
          min={1}
          value={pair.points}
          onValueChange={(points) => setPair({ ...pair, points })}
        >
          <NumberInputInput aria-label={__('Points received', 'yayloyalty')} />
          <NumberInputUnit unit={__('pts', 'yayloyalty')} />
        </NumberInputRoot>
      </div>

      {result !== null && (
        <Badge variant="primary-soft">
          {sprintf(
            /* translators: 1: points per one currency unit, e.g. "0.05"; 2: one unit of the store currency, e.g. "1 ₫". */
            __('= %1$s pts per %2$s', 'yayloyalty'),
            formatStoreDecimal(result, 6),
            formatWPCurrency(1),
          )}
        </Badge>
      )}

      <DialogFooter>
        <Button variant="outline" onClick={onCancel}>
          {__('Cancel', 'yayloyalty')}
        </Button>
        <Button disabled={result === null} onClick={() => result !== null && onApply(result)}>
          {__('Use this rate', 'yayloyalty')}
        </Button>
      </DialogFooter>
    </>
  );
}
