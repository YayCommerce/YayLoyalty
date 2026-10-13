import { Controller, useFormContext, useWatch } from 'react-hook-form';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Switch } from '@/components/ui/switch';
import {
  INACTIVITY_AFTER_LABEL,
  INACTIVITY_DAYS_UNIT,
  INACTIVITY_OFF_HINT,
  INACTIVITY_SINCE,
  INACTIVITY_TITLE,
} from './expiry-settings.copy';
import { ExpirySettingsFormValues } from './expiry-settings.form';

/** Inactivity expiry: the on/off switch and the window in days, disabled while off. */
export function ExpiryInactivityCard() {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const isEnabled = useWatch({ control, name: 'expiry.enabled' });

  return (
    <Card className="gap-3">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="flex flex-col gap-1.5">
          <CardTitle>
            <label htmlFor="expiry-inactivity-enabled">{INACTIVITY_TITLE()}</label>
          </CardTitle>
          <CardDescription>{INACTIVITY_OFF_HINT()}</CardDescription>
        </div>
        <Controller
          control={control}
          name="expiry.enabled"
          render={({ field }) => (
            <Switch
              id="expiry-inactivity-enabled"
              className="w-11!"
              checked={field.value}
              onCheckedChange={field.onChange}
            />
          )}
        />
      </CardHeader>
      <CardContent className="flex flex-wrap items-center gap-3">
        <label htmlFor="expiry-inactivity-days" className="text-sm">
          {INACTIVITY_AFTER_LABEL()}
        </label>
        <Controller
          control={control}
          name="expiry.inactivity_days"
          render={({ field }) => (
            <NumberInputRoot
              size="small"
              className="w-32"
              min={1}
              disabled={!isEnabled}
              value={field.value}
              onValueChange={field.onChange}
            >
              <NumberInputInput id="expiry-inactivity-days" />
              <NumberInputUnit unit={INACTIVITY_DAYS_UNIT()} />
            </NumberInputRoot>
          )}
        />
        <span className="text-muted-foreground text-sm">{INACTIVITY_SINCE()}</span>
      </CardContent>
    </Card>
  );
}
