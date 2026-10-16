import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { Link } from '@/lib/router';
import { REMINDER_DAYS_MAX } from '@/lib/schema/settings.schema';
import { Card, CardContent } from '@/components/ui/card';
import { FieldError } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { ExpirySettingsFormValues } from './expiry-settings.form';

/** "Remind before expiry": how many days ahead the Points expiring soon email goes out, in both expiry modes. */
export function ExpiryReminderRow() {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const isEnabled = useWatch({ control, name: 'expiry.enabled' });

  return (
    <Card className="gap-3">
      <CardContent>
        <Controller
          control={control}
          name="expiry.reminder_days"
          render={({ field, fieldState }) => (
            <div className="flex flex-col gap-2">
              <div className="flex flex-wrap items-center gap-3">
                <label htmlFor="expiry-reminder-days" className="text-sm font-medium">
                  {__('Remind before expiry', 'yayloyalty')}
                </label>
                <NumberInputRoot
                  size="small"
                  className="w-32"
                  min={1}
                  max={REMINDER_DAYS_MAX}
                  disabled={!isEnabled}
                  value={field.value}
                  onValueChange={field.onChange}
                >
                  <NumberInputInput id="expiry-reminder-days" aria-invalid={!!fieldState.error} />
                  <NumberInputUnit unit={__('days', 'yayloyalty')} />
                </NumberInputRoot>
                <span className="text-muted-foreground text-sm">
                  {__('sends the “Points expiring soon” email ·', 'yayloyalty')}{' '}
                  <Link to="/settings/emails" className="text-primary underline">
                    {__('Emails', 'yayloyalty')}
                  </Link>
                </span>
              </div>
              <FieldError errors={[fieldState.error]} />
            </div>
          )}
        />
      </CardContent>
    </Card>
  );
}
