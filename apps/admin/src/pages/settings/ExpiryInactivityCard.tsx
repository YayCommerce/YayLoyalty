import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { POINTS_EXPIRED_EMAIL_ID } from '@/lib/schema/emails.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Switch } from '@/components/ui/switch';
import { ExpirySettingsFormValues } from './expiry-settings.form';
import { ExpiryEmailOffHint } from './ExpiryEmailOffHint';

/**
 * Inactivity expiry: the window in days, disabled while off. Shows its own on/off switch unless
 * `showSwitch` is false, where the card above it holds the switch.
 */
export function ExpiryInactivityCard({ showSwitch = true }: { showSwitch?: boolean }) {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const isEnabled = useWatch({ control, name: 'expiry.enabled' });

  if (!showSwitch) {
    return (
      <Card className="gap-3">
        <CardHeader>
          <CardTitle>{__('Inactivity expiry', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <InactivityDaysRow isEnabled={isEnabled} />
      </Card>
    );
  }

  return (
    <Card className="gap-3">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="flex flex-col gap-1.5">
          <CardTitle>
            <label htmlFor="expiry-inactivity-enabled">{__('Expire points after inactivity', 'yayloyalty')}</label>
          </CardTitle>
          <CardDescription>{__('When off, balances are kept until they are spent.', 'yayloyalty')}</CardDescription>
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
      <InactivityDaysRow isEnabled={isEnabled} />
    </Card>
  );
}

function InactivityDaysRow({ isEnabled }: { isEnabled: boolean }) {
  const { control } = useFormContext<ExpirySettingsFormValues>();

  return (
    <CardContent className="flex flex-wrap items-center gap-3">
      <label htmlFor="expiry-inactivity-days" className="text-sm">
        {__('Expire after', 'yayloyalty')}
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
            <NumberInputUnit unit={__('days', 'yayloyalty')} />
          </NumberInputRoot>
        )}
      />
      <span className="text-muted-foreground text-sm">
        {__('since the customer last earned or redeemed', 'yayloyalty')}
      </span>
      {isEnabled && (
        <div className="basis-full">
          <ExpiryEmailOffHint
            emailId={POINTS_EXPIRED_EMAIL_ID}
            message={__('The “Points expired” email is off.', 'yayloyalty')}
            successMessage={__('Points expired email turned on.', 'yayloyalty')}
          />
        </div>
      )}
    </CardContent>
  );
}
