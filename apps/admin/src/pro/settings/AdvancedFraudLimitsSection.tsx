import { Controller, useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { OptionalCountInput } from '@/components/ui/custom/amount-inputs';
import { Label } from '@/components/ui/label';
import { FRAUD_LIMITS_DESCRIPTION, FRAUD_LIMITS_TITLE } from '@/pages/settings/advanced-settings.copy';
import type { AdvancedSettingsFormValues } from '@/pages/settings/advanced-settings.form';

const FIELD_ID = 'yayloyalty-max-points-per-day';

/**
 * Settings → Advanced "Fraud limits": the per-customer daily cap that Pro's DailyEarningLimit (PHP)
 * enforces at award time. A section of the tab's one form; AdvancedSettingsPage owns Save.
 */
export function AdvancedFraudLimitsSection() {
  const { control } = useFormContext<AdvancedSettingsFormValues>();

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{FRAUD_LIMITS_TITLE()}</CardTitle>
        <CardDescription>{FRAUD_LIMITS_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        <Label htmlFor={FIELD_ID}>{__('Max points per customer per day', 'yayloyalty')}</Label>
        <Controller
          control={control}
          name="advanced.max_points_per_day"
          render={({ field }) => (
            <OptionalCountInput
              id={FIELD_ID}
              className="w-40"
              value={field.value}
              unit={__('points', 'yayloyalty')}
              onValueChange={field.onChange}
            />
          )}
        />
        <p className="text-muted-foreground text-xs">
          {__(
            'Earning above this is not awarded and is written to the log. Points already awarded are never removed. Leave empty for no limit.',
            'yayloyalty',
          )}
        </p>
      </CardContent>
    </Card>
  );
}
