import { useState } from 'react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import {
  TIERS_CALENDAR_PERIODS,
  TIERS_ROLLING_MONTHS,
  tiersCalendarPeriodSchema,
  tiersRollingMonthsSchema,
  type TiersCalendarPeriod,
  type TierSettings,
  type TierSettingsInput,
} from '@/lib/schema/tier.schema';
import { cn } from '@/lib/utils';
import { LoadingButton } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useTierSettingsQuery, useUpdateTierSettingsMutation } from './tiers.queries';

type TierMode = TierSettings['tiers_mode'];
type RollingMonths = TierSettings['tiers_rolling_months'];

/** Which confirm a save asks for; none while tiers are off. */
type Change = 'to_rolling' | 'to_lifetime' | 'to_calendar' | 'months' | 'period';

const PERIOD_LABELS: Record<TiersCalendarPeriod, () => string> = {
  month: () => __('Monthly', 'yayloyalty'),
  quarter: () => __('Quarterly', 'yayloyalty'),
  year: () => __('Yearly', 'yayloyalty'),
};

/** The Calendar confirms' description, one full sentence per period. */
const PERIOD_KEEPS: Record<TiersCalendarPeriod, () => string> = {
  month: () =>
    __(
      'Every current member keeps their tier until the end of this month. Then each is reviewed on the points they earned in it.',
      'yayloyalty',
    ),
  quarter: () =>
    __(
      'Every current member keeps their tier until the end of this quarter. Then each is reviewed on the points they earned in it.',
      'yayloyalty',
    ),
  year: () =>
    __(
      'Every current member keeps their tier until the end of this year. Then each is reviewed on the points they earned in it.',
      'yayloyalty',
    ),
};

/** The confirm for a save that changes the mode, by the target mode. */
const MODE_CHANGES: Record<TierMode, Change> = {
  lifetime: 'to_lifetime',
  rolling: 'to_rolling',
  calendar: 'to_calendar',
};

const MODES: { value: TierMode; label: () => string; description: () => string }[] = [
  {
    value: 'lifetime',
    label: () => __('Lifetime', 'yayloyalty'),
    description: () =>
      __('Points earned since joining. Customers move down only after refunds or deductions.', 'yayloyalty'),
  },
  {
    value: 'rolling',
    label: () => __('Rolling', 'yayloyalty'),
    description: () =>
      __('Points earned in a recent window. Reaching a tier holds it for the same number of months.', 'yayloyalty'),
  },
  {
    value: 'calendar',
    label: () => __('Calendar', 'yayloyalty'),
    description: () =>
      __(
        'Points earned in each calendar month, quarter or year. A tier is kept for the rest of that period and all of the next.',
        'yayloyalty',
      ),
  },
];

/**
 * The Tiers page "Qualification" card: how customers qualify (Lifetime, Rolling or Calendar), with Rolling's window
 * length and Calendar's period.
 */
export function TierModeCard() {
  const { data: settings } = useTierSettingsQuery();

  // TierSwitchCard shows the load error for the shared settings query.
  if (!settings) {
    return <Skeleton className="h-56 w-full rounded-xl" />;
  }

  return <TierModeForm settings={settings} />;
}

/** Mounted once the settings load, so the local state starts from the stored values. */
function TierModeForm({ settings }: { settings: TierSettings }) {
  const updateSettings = useUpdateTierSettingsMutation();
  const [mode, setMode] = useState<TierMode>(settings.tiers_mode);
  const [months, setMonths] = useState<RollingMonths>(settings.tiers_rolling_months);
  const [period, setPeriod] = useState<TiersCalendarPeriod>(settings.tiers_calendar_period);
  const [confirming, setConfirming] = useState<Change>();

  const input: TierSettingsInput = {};

  if (mode !== settings.tiers_mode) {
    input.tiers_mode = mode;
  }

  if (mode === 'rolling' && months !== settings.tiers_rolling_months) {
    input.tiers_rolling_months = months;
  }

  if (mode === 'calendar' && period !== settings.tiers_calendar_period) {
    input.tiers_calendar_period = period;
  }

  const isDirty = Object.keys(input).length > 0;
  const isSweeping = settings.sweep !== null;
  const change: Change = input.tiers_mode ? MODE_CHANGES[mode] : mode === 'calendar' ? 'period' : 'months';

  function onSave() {
    if (settings.tiers_enabled) {
      setConfirming(change);
    } else {
      save();
    }
  }

  function save() {
    setConfirming(undefined);
    updateSettings.mutate(input, {
      onSuccess: () => toast.success(__('Qualification saved.', 'yayloyalty')),
      onError: (error) => toastApiError(error, __('Could not save the qualification.', 'yayloyalty')),
    });
  }

  const confirms: Record<Change, { title: string; description: string; confirmLabel: string }> = {
    to_rolling: {
      title: __('Switch to rolling qualification?', 'yayloyalty'),
      description: sprintf(
        /* translators: %1$s: the rolling window's length in months. */
        __(
          'Every current member keeps their tier for %1$s months. After that, they move to the tier their last %1$s months of points qualify for.',
          'yayloyalty',
        ),
        String(months),
      ),
      confirmLabel: __('Switch', 'yayloyalty'),
    },
    to_lifetime: {
      title: __('Switch to lifetime qualification?', 'yayloyalty'),
      description: __(
        'Holds are cleared, and customers move up to the tier their lifetime points qualify for. No one moves down.',
        'yayloyalty',
      ),
      confirmLabel: __('Switch', 'yayloyalty'),
    },
    to_calendar: {
      title: __('Switch to calendar qualification?', 'yayloyalty'),
      description: PERIOD_KEEPS[period](),
      confirmLabel: __('Switch', 'yayloyalty'),
    },
    period: {
      title: __('Change the calendar period?', 'yayloyalty'),
      description: PERIOD_KEEPS[period](),
      confirmLabel: __('Change', 'yayloyalty'),
    },
    months: {
      title: __('Change the rolling window?', 'yayloyalty'),
      description: __(
        "Current holds keep their end dates. The new window applies from each customer's next evaluation.",
        'yayloyalty',
      ),
      confirmLabel: __('Change', 'yayloyalty'),
    },
  };

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle>{__('Qualification', 'yayloyalty')}</CardTitle>
          <CardDescription>{__('How customers qualify for a tier.', 'yayloyalty')}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <RadioGroup
            className="grid gap-3 sm:grid-cols-3"
            value={mode}
            onValueChange={(next) => setMode(next as TierMode)}
            aria-label={__('Qualification', 'yayloyalty')}
          >
            {MODES.map((choice) => (
              <label
                key={choice.value}
                className={cn(
                  'flex cursor-pointer items-start gap-3 rounded-md border p-3',
                  mode === choice.value && 'border-primary',
                )}
              >
                <RadioGroupItem value={choice.value} className="mt-0.5" />
                <span className="flex flex-col gap-1">
                  <span className="text-sm font-medium">{choice.label()}</span>
                  <span className="text-muted-foreground text-xs">{choice.description()}</span>
                </span>
              </label>
            ))}
          </RadioGroup>

          {mode === 'rolling' && (
            <Field>
              <FieldLabel htmlFor="yayloyalty-tiers-rolling-months">{__('Window', 'yayloyalty')}</FieldLabel>
              <FieldContent>
                <Select
                  value={String(months)}
                  onValueChange={(next) => setMonths(tiersRollingMonthsSchema.parse(Number(next)))}
                >
                  <SelectTrigger id="yayloyalty-tiers-rolling-months" className="w-48">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TIERS_ROLLING_MONTHS.map((value) => (
                      <SelectItem key={value} value={String(value)}>
                        {sprintf(
                          /* translators: %s: a number of months. */
                          __('%s months', 'yayloyalty'),
                          String(value),
                        )}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </FieldContent>
            </Field>
          )}

          {mode === 'calendar' && (
            <Field>
              <FieldLabel htmlFor="yayloyalty-tiers-calendar-period">{__('Period', 'yayloyalty')}</FieldLabel>
              <FieldContent>
                <Select value={period} onValueChange={(next) => setPeriod(tiersCalendarPeriodSchema.parse(next))}>
                  <SelectTrigger id="yayloyalty-tiers-calendar-period" className="w-48">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TIERS_CALENDAR_PERIODS.map((value) => (
                      <SelectItem key={value} value={value}>
                        {PERIOD_LABELS[value]()}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </FieldContent>
            </Field>
          )}
        </CardContent>
        <CardFooter className="flex flex-wrap items-center gap-3">
          <LoadingButton
            loading={updateSettings.isPending}
            disabled={!isDirty || isSweeping || updateSettings.isPending}
            onClick={onSave}
          >
            {__('Save', 'yayloyalty')}
          </LoadingButton>
          {isSweeping ? (
            <span className="text-muted-foreground text-xs">
              {__('Wait for the current tier update to finish.', 'yayloyalty')}
            </span>
          ) : (
            !settings.tiers_enabled && (
              <span className="text-muted-foreground text-xs">
                {__('Applies when you turn tiers on.', 'yayloyalty')}
              </span>
            )
          )}
        </CardFooter>
      </Card>

      {confirming && (
        <ConfirmDialog
          open
          onOpenChange={(open) => !open && setConfirming(undefined)}
          title={confirms[confirming].title}
          description={confirms[confirming].description}
          confirmLabel={confirms[confirming].confirmLabel}
          cancelLabel={__('Cancel', 'yayloyalty')}
          onConfirm={save}
        />
      )}
    </>
  );
}
