import { Controller, useWatch } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { formatStoreDecimal, parseWPCurrency, storeCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { MoneyInput, PointsInput } from '@/components/ui/custom/amount-inputs';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { pointsForSpend, valueOfPoints, type WizardStepProps } from './setup-wizard.form';
import { WizardPreview, WizardSection, WizardStepHeading } from './WizardStepParts';

/** Step 3 (artboard 1c): the built-in cart discount's rate and limits. */
export function SpendPointsStep({ form }: WizardStepProps) {
  const [earnPoints, earnSpend, worthPoints, worthAmount, minPoints] = useWatch({
    control: form.control,
    name: ['earnPoints', 'earnSpend', 'worthPoints', 'worthAmount', 'minPoints'],
  });
  const worth = { worthPoints, worthAmount };
  // Share of each sale given back: what 100 spent earns, valued at the redemption rate.
  const givenBack = valueOfPoints(worth, pointsForSpend({ earnPoints, earnSpend }, 100));
  const samplePoints = worthAmount > 0 ? Math.round((5 * worthPoints) / worthAmount) : 0;

  return (
    <>
      <WizardStepHeading
        title={__('How do customers spend their points?', 'yayloyalty')}
        description={__('One reward, ready at checkout. You can add a reward catalog later.', 'yayloyalty')}
      />
      <WizardSection>
        <div className="flex flex-col gap-2.5">
          <div className="text-sm font-semibold">{__('What are points worth?', 'yayloyalty')}</div>
          <div className="flex flex-wrap items-center gap-2.5">
            <Controller
              control={form.control}
              name="worthPoints"
              render={({ field }) => <PointsInput value={field.value} min={1} onValueChange={field.onChange} />}
            />
            <span className="text-muted-foreground text-sm">=</span>
            <Controller
              control={form.control}
              name="worthAmount"
              render={({ field }) => (
                <MoneyInput value={field.value} currency={storeCurrency()} onValueChange={field.onChange} />
              )}
            />
            <span className="text-muted-foreground text-sm">{__('off an order', 'yayloyalty')}</span>
          </div>
          <div className="text-muted-foreground text-xs">
            {sprintf(
              /* translators: %s: the percentage of spend a customer earns back, e.g. 1. */
              __('At the rate in step 2, a customer earns back %s%% of what they spend.', 'yayloyalty'),
              formatStoreDecimal(givenBack, 2),
            )}
          </div>
        </div>
        <WizardPreview>
          {sprintf(
            /* translators: 1: a points amount, formatted; 2: the discount it buys, formatted. */
            __('%1$s points = %2$s off', 'yayloyalty'),
            formatPoints(samplePoints),
            parseWPCurrency(5),
          )}
        </WizardPreview>
        <div className="grid grid-cols-2 gap-5 border-t pt-4">
          <div className="flex flex-col gap-2.5">
            <div className="text-sm font-semibold">{__('Smallest redemption', 'yayloyalty')}</div>
            <div className="flex items-center gap-2.5">
              <Controller
                control={form.control}
                name="minPoints"
                render={({ field }) => <PointsInput value={field.value} min={0} onValueChange={field.onChange} />}
              />
              <span className="text-muted-foreground text-xs">
                = {parseWPCurrency(valueOfPoints(worth, minPoints))}
              </span>
            </div>
            <div className="text-muted-foreground text-xs">
              {__('Below this the slider is disabled, so nobody spends 7 points for 7 cents.', 'yayloyalty')}
            </div>
          </div>
          <div className="flex flex-col gap-2.5">
            <div className="text-sm font-semibold">{__('Most of an order points can cover', 'yayloyalty')}</div>
            <div className="flex items-center gap-2.5">
              <Controller
                control={form.control}
                name="maxPercent"
                render={({ field }) => (
                  <NumberInputRoot
                    size="small"
                    className="w-28"
                    min={1}
                    max={100}
                    value={field.value}
                    onValueChange={field.onChange}
                  >
                    <NumberInputInput />
                    <NumberInputUnit unit="%" />
                  </NumberInputRoot>
                )}
              />
              <span className="text-muted-foreground text-xs">{__('of the subtotal', 'yayloyalty')}</span>
            </div>
            <div className="text-muted-foreground text-xs">
              {__(
                '100% lets a loyal customer pay entirely in points. Lower it if you always want a card payment.',
                'yayloyalty',
              )}
            </div>
          </div>
        </div>
      </WizardSection>
    </>
  );
}
