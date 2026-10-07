import { Controller, useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { EmailsFormValues } from '@/lib/schema/emails.schema';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';

/** Weekly per-customer send cap, enforced by EmailThrottle when an email sends. */
export function EmailsThrottleCard() {
  const { control } = useFormContext<EmailsFormValues>();

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{__('Throttle', 'yayloyalty')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div className="flex items-center gap-3">
          <Controller
            control={control}
            name="throttle_per_week"
            render={({ field }) => (
              <NumberInputRoot className="w-32" min={0} value={field.value} onValueChange={field.onChange}>
                <NumberInputInput />
                <NumberInputUnit unit={__('per week', 'yayloyalty')} />
              </NumberInputRoot>
            )}
          />
          <span className="text-muted-foreground text-sm">
            {__('maximum loyalty emails per customer', 'yayloyalty')}
          </span>
        </div>
        <p className="text-muted-foreground text-xs">
          {__("Order and account emails from WooCommerce aren't counted toward this cap.", 'yayloyalty')}
        </p>
        <p className="text-muted-foreground text-xs">{__('Set 0 for no limit.', 'yayloyalty')}</p>
      </CardContent>
    </Card>
  );
}
