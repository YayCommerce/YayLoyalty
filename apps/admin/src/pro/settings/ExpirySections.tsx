import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { useSettingsQuery } from '@/lib/queries/settings.queries';
import { cn } from '@/lib/utils';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Switch } from '@/components/ui/switch';
import type { ExpirySettingsFormValues } from '@/pages/settings/expiry-settings.form';

const MODES = [
  {
    value: 'inactivity',
    label: () => __('When a customer goes inactive', 'yayloyalty'),
    description: () => __('The whole balance expires after a period with no earning or redemption.', 'yayloyalty'),
  },
  {
    value: 'batch',
    label: () => __('When each batch of points ages out', 'yayloyalty'),
    description: () => __('Each earning expires a set time after it was earned, oldest spent first.', 'yayloyalty'),
  },
] as const;

/**
 * Settings → Expiry "Points expiry": the on/off switch, then the model, which is picked only while on.
 * Batch is one-way: once saved, the inactivity option is disabled and the server refuses the switch back.
 * A section of the tab's one form; ExpirySettingsPage owns Save.
 */
export function ExpiryModeSection() {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const isEnabled = useWatch({ control, name: 'expiry.enabled' });
  const isBatchSaved = useSettingsQuery().data.expiry.mode === 'batch';

  return (
    <Card className="gap-3">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="flex flex-col gap-1.5">
          <CardTitle>
            <label htmlFor="expiry-enabled">{__('Points expiry', 'yayloyalty')}</label>
          </CardTitle>
          <CardDescription>{__('When off, balances are kept until they are spent.', 'yayloyalty')}</CardDescription>
        </div>
        <Controller
          control={control}
          name="expiry.enabled"
          render={({ field }) => (
            <Switch id="expiry-enabled" className="w-11!" checked={field.value} onCheckedChange={field.onChange} />
          )}
        />
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        <Controller
          control={control}
          name="expiry.mode"
          render={({ field }) => (
            <RadioGroup className="grid gap-3 sm:grid-cols-2" value={field.value} onValueChange={field.onChange}>
              {MODES.map((mode) => {
                const isDisabled = !isEnabled || (mode.value === 'inactivity' && isBatchSaved);

                return (
                  <label
                    key={mode.value}
                    className={cn(
                      'flex cursor-pointer items-start gap-3 rounded-md border p-3',
                      field.value === mode.value && 'border-primary',
                      isDisabled && 'cursor-not-allowed opacity-60',
                    )}
                  >
                    <RadioGroupItem value={mode.value} disabled={isDisabled} className="mt-0.5" />
                    <span className="flex flex-col gap-1">
                      <span className="text-sm font-medium">{mode.label()}</span>
                      <span className="text-muted-foreground text-xs">{mode.description()}</span>
                    </span>
                  </label>
                );
              })}
            </RadioGroup>
          )}
        />
        <p className="text-muted-foreground text-xs">{__('Switching models is a one-way migration.', 'yayloyalty')}</p>
      </CardContent>
    </Card>
  );
}

/** Settings → Expiry in batch mode: the months each lot lasts and the spend order, disabled while expiry is off. */
export function BatchExpirySection() {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const isEnabled = useWatch({ control, name: 'expiry.enabled' });

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{__('Batch expiry', 'yayloyalty')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <label htmlFor="expiry-batch-months">{__('Points expire', 'yayloyalty')}</label>
          <Controller
            control={control}
            name="expiry.batch_months"
            render={({ field }) => (
              <NumberInputRoot
                size="small"
                className="w-32"
                min={1}
                disabled={!isEnabled}
                value={field.value}
                onValueChange={field.onChange}
              >
                <NumberInputInput id="expiry-batch-months" />
                <NumberInputUnit unit={__('months', 'yayloyalty')} />
              </NumberInputRoot>
            )}
          />
          <span>{__('after the day they were earned', 'yayloyalty')}</span>
        </div>
        <div className="flex flex-col gap-2">
          <span className="text-sm font-medium">{__('Spend order', 'yayloyalty')}</span>
          <Controller
            control={control}
            name="expiry.spend_order"
            render={({ field }) => (
              <Segmented
                shape="square"
                size="sm"
                aria-label={__('Spend order', 'yayloyalty')}
                className="w-max"
                value={field.value}
                disabled={!isEnabled}
                // A second click on the active segment reports '': there is no "none" order.
                onValueChange={(next) => next && field.onChange(next)}
              >
                <SegmentedItem value="oldest" className="px-2.5">
                  {__('Oldest first', 'yayloyalty')}
                </SegmentedItem>
                <SegmentedItem value="newest" className="px-2.5">
                  {__('Newest first', 'yayloyalty')}
                </SegmentedItem>
              </Segmented>
            )}
          />
          <p className="text-muted-foreground text-xs">
            {__('Oldest first means fewer points expire unused.', 'yayloyalty')}
          </p>
        </div>
      </CardContent>
    </Card>
  );
}
