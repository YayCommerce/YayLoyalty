import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Switch } from '@/components/ui/switch';
import type { ExpirySettingsFormValues } from '@/pages/settings/expiry-settings.form';

/** Settings → Expiry in batch mode: the switch, the months each lot lasts, and the spend order. */
export function BatchExpirySection() {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const isEnabled = useWatch({ control, name: 'expiry.enabled' });

  return (
    <Card className="gap-3">
      <CardHeader className="flex flex-row items-center justify-between gap-4">
        <CardTitle>
          <label htmlFor="expiry-batch-enabled">{__('Expire points after they are earned', 'yayloyalty')}</label>
        </CardTitle>
        <Controller
          control={control}
          name="expiry.enabled"
          render={({ field }) => (
            <Switch
              id="expiry-batch-enabled"
              className="w-11!"
              checked={field.value}
              onCheckedChange={field.onChange}
            />
          )}
        />
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
