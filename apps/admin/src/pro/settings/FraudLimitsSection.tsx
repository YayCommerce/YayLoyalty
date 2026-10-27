import { Controller, useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { AMOUNT_INPUT_WIDTH, OptionalCountInput } from '@/components/ui/custom/amount-inputs';
import { Label } from '@/components/ui/label';
import { FRAUD_LIMITS_DESCRIPTION, FRAUD_LIMITS_TITLE } from '@/pages/settings/general-settings.copy';
import type { GeneralSettingsFormValues } from '@/pages/settings/general-settings.form';

const FIELD_ID = 'yayloyalty-max-points-per-day';

/**
 * Settings → General "Fraud limits": the per-customer daily cap that Pro's DailyEarningLimit (PHP)
 * enforces at award time. A section of the tab's one form; GeneralSettingsPage owns Save.
 */
export function FraudLimitsSection() {
  const { control } = useFormContext<GeneralSettingsFormValues>();

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{FRAUD_LIMITS_TITLE()}</CardTitle>
        <CardDescription>{FRAUD_LIMITS_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        <Label htmlFor={FIELD_ID}>{__('Daily limit per customer', 'yayloyalty')}</Label>
        <Controller
          control={control}
          name="max_points_per_day"
          render={({ field }) => (
            <OptionalCountInput
              id={FIELD_ID}
              className={AMOUNT_INPUT_WIDTH}
              value={field.value}
              unit={__('points', 'yayloyalty')}
              onValueChange={field.onChange}
            />
          )}
        />
        <p className="text-muted-foreground text-xs">{__('Leave empty for no limit.', 'yayloyalty')}</p>
      </CardContent>
    </Card>
  );
}
