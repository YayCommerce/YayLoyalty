import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { OptionalCountInput } from '@/components/ui/custom/amount-inputs';
import { Label } from '@/components/ui/label';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import type { ExpirySettingsFormValues } from '@/pages/settings/expiry-settings.form';

const FINAL_ID = 'yayloyalty-expiry-final-reminder-days';
const FLOOR_ID = 'yayloyalty-expiry-reminder-min-points';

/** Mirrors SettingsHelper::RANGES['reminder_days'], the source of truth. */
const FINAL_RANGE = { min: 0, max: 365 };

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
          <Label htmlFor={FINAL_ID}>{__('Second reminder', 'yayloyalty')}</Label>
          <div className="flex flex-wrap items-center gap-3">
            <Controller
              control={control}
              name="final_reminder_days"
              render={({ field }) => (
                <NumberInputRoot
                  size="small"
                  className="w-32"
                  min={FINAL_RANGE.min}
                  max={FINAL_RANGE.max}
                  value={field.value}
                  onValueChange={(value) => field.onChange(value ?? FINAL_RANGE.min)}
                >
                  <NumberInputInput id={FINAL_ID} />
                  <NumberInputUnit unit={__('days', 'yayloyalty')} />
                </NumberInputRoot>
              )}
            />
            <span className="text-muted-foreground text-sm">
              {__('before · “Final reminder” ·', 'yayloyalty')}{' '}
              <Link to="/settings/emails" className="text-primary underline">
                {__('Emails', 'yayloyalty')}
              </Link>
            </span>
          </div>
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
