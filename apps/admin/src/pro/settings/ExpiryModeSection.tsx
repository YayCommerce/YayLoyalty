import { Controller, useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { useSettingsQuery } from '@/lib/queries/settings.queries';
import { cn } from '@/lib/utils';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
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
 * Settings → Expiry "Expiry model". Batch is one-way: once saved, the inactivity option is disabled
 * and the server refuses the switch back. A section of the tab's one form; ExpirySettingsPage owns Save.
 */
export function ExpiryModeSection() {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const isBatchSaved = useSettingsQuery().data.expiry.mode === 'batch';

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{__('Expiry model', 'yayloyalty')}</CardTitle>
        <CardDescription>{__('Switching models is a one-way migration.', 'yayloyalty')}</CardDescription>
      </CardHeader>
      <CardContent>
        <Controller
          control={control}
          name="expiry.mode"
          render={({ field }) => (
            <RadioGroup className="grid gap-3 sm:grid-cols-2" value={field.value} onValueChange={field.onChange}>
              {MODES.map((mode) => {
                const isDisabled = mode.value === 'inactivity' && isBatchSaved;

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
      </CardContent>
    </Card>
  );
}
