import { Controller, useWatch } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency, storeCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { MoneyInput, PointsInput } from '@/components/ui/custom/amount-inputs';
import { Switch } from '@/components/ui/switch';
import { pointsForSpend, type SetupForm, type WizardStepProps } from './setup-wizard.form';
import { WizardPreview, WizardSection, WizardStepHeading } from './WizardStepParts';

/** Step 2 (artboard 1b): the purchase rate plus the signup and review suggestions, the three built-in campaigns. */
export function EarnPointsStep({ form }: WizardStepProps) {
  const [earnPoints, earnSpend] = useWatch({ control: form.control, name: ['earnPoints', 'earnSpend'] });

  return (
    <>
      <WizardStepHeading
        title={__('How do customers earn points?', 'yayloyalty')}
        description={__('One rate, plus two suggestions you can switch off. Still nothing published.', 'yayloyalty')}
      />
      <WizardSection>
        <div className="flex flex-col gap-2.5">
          <div className="text-sm font-semibold">{__('Points for spending money', 'yayloyalty')}</div>
          <div className="flex flex-wrap items-center gap-2.5">
            <Controller
              control={form.control}
              name="earnPoints"
              render={({ field }) => <PointsInput value={field.value} min={1} onValueChange={field.onChange} />}
            />
            <span className="text-muted-foreground text-sm">{__('for every', 'yayloyalty')}</span>
            <Controller
              control={form.control}
              name="earnSpend"
              render={({ field }) => (
                <MoneyInput value={field.value} currency={storeCurrency()} onValueChange={field.onChange} />
              )}
            />
            <span className="text-muted-foreground text-sm">{__('spent', 'yayloyalty')}</span>
          </div>
          <div className="text-muted-foreground text-xs">
            {__('Calculated on the order subtotal, before shipping and tax.', 'yayloyalty')}
          </div>
        </div>
        <WizardPreview>
          {sprintf(
            /* translators: 1: a sample order total, formatted; 2: the points it earns, formatted. */
            __('A %1$s order earns %2$s points', 'yayloyalty'),
            parseWPCurrency(50),
            formatPoints(pointsForSpend({ earnPoints, earnSpend }, 50)),
          )}
        </WizardPreview>
        <div className="flex flex-col border-t pt-4">
          <div className="flex items-center gap-2.5 pb-1">
            <div className="text-sm font-semibold">{__('Two more ways to earn, suggested', 'yayloyalty')}</div>
            <div className="text-muted-foreground ml-auto text-xs">
              {__('both on · switch off any you do not want', 'yayloyalty')}
            </div>
          </div>
          <SuggestionRow
            form={form}
            enabled="signupEnabled"
            points="signupPoints"
            title={__('Points for creating an account', 'yayloyalty')}
            hint={__('Awarded once, when the account is created.', 'yayloyalty')}
          />
          <SuggestionRow
            form={form}
            enabled="reviewEnabled"
            points="reviewPoints"
            title={__('Points for writing a product review', 'yayloyalty')}
            hint={__('Verified purchases only, so reviews cannot be farmed for points.', 'yayloyalty')}
          />
        </div>
      </WizardSection>
    </>
  );
}

type SuggestionRowProps = WizardStepProps & {
  enabled: keyof Pick<SetupForm, 'signupEnabled' | 'reviewEnabled'>;
  points: keyof Pick<SetupForm, 'signupPoints' | 'reviewPoints'>;
  title: string;
  hint: string;
};

function SuggestionRow({ form, enabled, points, title, hint }: SuggestionRowProps) {
  const isOn = useWatch({ control: form.control, name: enabled });

  return (
    <div className="flex items-start gap-3 border-t py-3.5">
      <Controller
        control={form.control}
        name={enabled}
        render={({ field }) => <Switch checked={field.value} onCheckedChange={field.onChange} aria-label={title} />}
      />
      <div className="flex flex-1 flex-col gap-1">
        <div className="text-sm font-semibold">{title}</div>
        <div className="text-muted-foreground text-xs">{hint}</div>
      </div>
      <div className={isOn ? undefined : 'pointer-events-none opacity-50'}>
        <Controller
          control={form.control}
          name={points}
          render={({ field }) => <PointsInput value={field.value} min={0} onValueChange={field.onChange} />}
        />
      </div>
    </div>
  );
}
