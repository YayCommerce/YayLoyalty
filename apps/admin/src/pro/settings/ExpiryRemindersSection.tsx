import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { OptionalCountInput } from '@/components/ui/custom/amount-inputs';
import { FormNumberField } from '@/components/ui/custom/form-number-field';
import { Label } from '@/components/ui/label';
import { expiryFieldRange, type ExpirySettingsFormValues } from '@/pages/settings/expiry-settings.form';

const FLOOR_ID = 'yayloyalty-expiry-reminder-min-points';

/**
 * Settings → Expiry, Pro: the final reminder and the reminder floor that Pro's ReminderStages (PHP) adds
 * to the expiry job. A section of the tab's one form; ExpirySettingsPage owns Save.
 */
export function ExpiryRemindersSection() {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const enabled = useWatch({ control, name: 'enabled' });

  if (!enabled) {
    return null;
  }

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{__('More reminders', 'yayloyalty')}</CardTitle>
        <CardDescription>
          {__('A last-chance email, and who is too low to email at all.', 'yayloyalty')}
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-col gap-1.5">
          <FormNumberField<ExpirySettingsFormValues>
            name="final_reminder_days"
            label={__('Second reminder', 'yayloyalty')}
            unit={__('days', 'yayloyalty')}
            hint={
              <>
                {__('before · “Final reminder” ·', 'yayloyalty')}{' '}
                <Link to="/settings/emails" className="text-primary underline">
                  {__('Emails', 'yayloyalty')}
                </Link>
              </>
            }
            {...expiryFieldRange('final_reminder_days')}
          />
          <p className="text-muted-foreground text-xs">{__('0 sends no second reminder.', 'yayloyalty')}</p>
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor={FLOOR_ID}>{__('Minimum balance to remind', 'yayloyalty')}</Label>
          <Controller
            control={control}
            name="reminder_min_points"
            render={({ field }) => (
              <OptionalCountInput
                id={FLOOR_ID}
                className="w-40"
                value={field.value}
                unit={__('points', 'yayloyalty')}
                onValueChange={field.onChange}
              />
            )}
          />
          <p className="text-muted-foreground text-xs">
            {__(
              "Don't email customers whose expiring points are below the minimum they could redeem. Leave empty to remind everyone.",
              'yayloyalty',
            )}
          </p>
        </div>
      </CardContent>
    </Card>
  );
}
