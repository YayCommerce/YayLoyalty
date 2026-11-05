import { useState } from 'react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import {
  TIERS_ROLLING_MONTHS,
  tiersRollingMonthsSchema,
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
type Change = 'to_rolling' | 'to_lifetime' | 'months';

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
];

/** The Tiers page "Qualification" card: how customers qualify, Lifetime or Rolling, and the window's length. */
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
  const [confirming, setConfirming] = useState<Change>();

  const input: TierSettingsInput = {};

  if (mode !== settings.tiers_mode) {
    input.tiers_mode = mode;
  }

  if (mode === 'rolling' && months !== settings.tiers_rolling_months) {
    input.tiers_rolling_months = months;
  }

  const isDirty = Object.keys(input).length > 0;
  const isSweeping = settings.sweep !== null;
  const change: Change = input.tiers_mode ? (mode === 'rolling' ? 'to_rolling' : 'to_lifetime') : 'months';

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
            className="grid gap-3 sm:grid-cols-2"
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
