import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { Link } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { useCustomersQuery } from '@/lib/queries/customer.queries';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
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
import type { ExpirySettingsFormValues } from './expiry-settings.form';

type DaysFieldName = 'inactivity_days' | 'reminder_days';

/** Mirrors SettingsHelper::RANGES, the source of truth, for the two day fields. */
const DAY_RANGES: Record<DaysFieldName, { min: number; max: number }> = {
  inactivity_days: { min: 1, max: 3650 },
  reminder_days: { min: 0, max: 365 },
};

/** The customers list filter the count and its link share: points expiring within 90 days. */
const EXPIRING_SOON = '90';

function DaysField({ name, label, hint }: { name: DaysFieldName; label: string; hint: React.ReactNode }) {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const id = `yayloyalty-expiry-${name.replaceAll('_', '-')}`;

  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      <div className="flex flex-wrap items-center gap-3">
        <Controller
          control={control}
          name={name}
          render={({ field }) => (
            <NumberInputRoot
              size="small"
              className="w-32"
              min={DAY_RANGES[name].min}
              max={DAY_RANGES[name].max}
              value={field.value}
              onValueChange={(value) => field.onChange(value ?? DAY_RANGES[name].min)}
            >
              <NumberInputInput id={id} />
              <NumberInputUnit unit={EXPIRY_DAYS_UNIT()} />
            </NumberInputRoot>
          )}
        />
        <span className="text-muted-foreground text-sm">{hint}</span>
      </div>
    </div>
  );
}

/** The rule as a dated sentence, from today, so the two numbers read as what a customer would see. */
function ExpiryExample({ days }: { days: number }) {
  const today = new Date();
  const expires = new Date(today.getTime() + days * 86_400_000);

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
        /* translators: %s: number of customers. */
        _n(
          '%s customer has points expiring in the next 90 days.',
          '%s customers have points expiring in the next 90 days.',
          total,
          'yayloyalty',
        ),
        total.toLocaleString(),
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
  // Only Pro can store batches; its section then owns the window, so this card keeps the switch and reminder.
  const isBatches = model === 'batches';

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
          {!isBatches && <DaysField name="inactivity_days" label={EXPIRY_AFTER_LABEL()} hint={EXPIRY_AFTER_HINT()} />}
          <DaysField
            name="reminder_days"
            label={EXPIRY_REMIND_LABEL()}
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
