import { addDays } from 'date-fns';
import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { Link } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useCustomersQuery } from '@/lib/queries/customer.queries';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FormNumberField } from '@/components/ui/custom/form-number-field';
import { Switch } from '@/components/ui/switch';
import {
  EXPIRY_AFTER_HINT,
  EXPIRY_AFTER_LABEL,
  EXPIRY_DAYS_UNIT,
  EXPIRY_DESCRIPTION,
  EXPIRY_EMAILS_LINK,
  EXPIRY_REMIND_HINT,
  EXPIRY_REMIND_LABEL,
  EXPIRY_TITLE,
  EXPIRY_TITLE_BATCHES,
  EXPIRY_VIEW_THEM,
} from './expiry-settings.copy';
import { expiryFieldRange, type ExpirySettingsFormValues } from './expiry-settings.form';

/** The customers list filter the count and its link share: points expiring within 90 days. */
const EXPIRING_SOON = '90';

/** The rule as a dated sentence, from today, so the two numbers read as what a customer would see. */
function ExpiryExample({ days }: { days: number }) {
  const today = new Date();
  const expires = addDays(today, days);

  return (
    <p className="text-muted-foreground text-sm">
      {sprintf(
        /* translators: 1: a date, 2: the date the balance would expire. */
        __(
          "A customer who last earned or spent points on %1$s will lose their balance on %2$s unless they're active before then.",
          'yayloyalty',
        ),
        parseWPDate(today),
        parseWPDate(expires),
      )}
    </p>
  );
}

/** How many customers the saved rules expire within 90 days, linked to that filtered customers list. */
function ExpiringSoonCount() {
  const query = useCustomersQuery({ perPage: 1, expiringSoon: EXPIRING_SOON });
  const total = query.data?.totalItems;

  if (total === undefined) {
    return null;
  }

  return (
    <p className="text-sm">
      {sprintf(
        /* translators: 1: number of customers, 2: number of days. */
        _n(
          '%1$s customer has points expiring in the next %2$s days.',
          '%1$s customers have points expiring in the next %2$s days.',
          total,
          'yayloyalty',
        ),
        total.toLocaleString(),
        EXPIRING_SOON,
      )}{' '}
      <Link to={`/customers?expiring_soon=${EXPIRING_SOON}`} className="text-primary underline">
        {EXPIRY_VIEW_THEM()}
      </Link>
    </p>
  );
}

/**
 * Settings → Expiry, free: one inactivity window and the reminder before it. PHP's ExpiryRules applies it,
 * and saving re-dates every customer (ExpiryRecompute).
 */
export function ExpiryInactivityCard() {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const [enabled, days, model] = useWatch({ control, name: ['enabled', 'inactivity_days', 'model'] });
  // Batches acts only in Pro, whose section then owns the window. Lite keeps a stored `batches` but expires by
  // inactivity (ExpiryRules::model()), so it shows the inactivity fields whatever is stored.
  const isBatches = IS_PRO_BUILD && model === 'batches';

  return (
    <Card className="gap-3">
      <CardHeader className="flex flex-row items-start gap-3">
        <Controller
          control={control}
          name="enabled"
          render={({ field }) => (
            <Switch
              id="yayloyalty-expiry-enabled"
              className="mt-0.5 w-11!"
              checked={field.value}
              onCheckedChange={field.onChange}
            />
          )}
        />
        <label htmlFor="yayloyalty-expiry-enabled" className="flex cursor-pointer flex-col gap-1">
          <CardTitle>{isBatches ? EXPIRY_TITLE_BATCHES() : EXPIRY_TITLE()}</CardTitle>
          <CardDescription>{EXPIRY_DESCRIPTION()}</CardDescription>
        </label>
      </CardHeader>
      {enabled && (
        <CardContent className="flex flex-col gap-4">
          {!isBatches && (
            <FormNumberField<ExpirySettingsFormValues>
              name="inactivity_days"
              label={EXPIRY_AFTER_LABEL()}
              unit={EXPIRY_DAYS_UNIT()}
              hint={EXPIRY_AFTER_HINT()}
              {...expiryFieldRange('inactivity_days')}
            />
          )}
          <FormNumberField<ExpirySettingsFormValues>
            name="reminder_days"
            label={EXPIRY_REMIND_LABEL()}
            unit={EXPIRY_DAYS_UNIT()}
            {...expiryFieldRange('reminder_days')}
            hint={
              <>
                {EXPIRY_REMIND_HINT()} ·{' '}
                <Link to="/settings/emails" className="text-primary underline">
                  {EXPIRY_EMAILS_LINK()}
                </Link>
              </>
            }
          />
          {!isBatches && <ExpiryExample days={days} />}
          <ExpiringSoonCount />
        </CardContent>
      )}
    </Card>
  );
}
