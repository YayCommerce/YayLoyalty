import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { ScheduleFields } from '@/components/ui/custom/schedule-fields';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { statusLabel } from '@/pro/campaigns/campaign-labels';
import { EDITABLE_REWARD_STATUSES, RewardFormValues } from './reward-editor.form';

/** Status, then the start and end dates side by side. */
export function RewardEditorStatusFields({ isBuiltin }: { isBuiltin: boolean }) {
  const { control, setValue, formState } = useFormContext<RewardFormValues>();
  const startsAt = useWatch({ control, name: 'starts_at' });
  const endsAt = useWatch({ control, name: 'ends_at' });
  // The built-in is switched off by pausing, never drafted.
  const statuses = isBuiltin
    ? EDITABLE_REWARD_STATUSES.filter((status) => status !== 'draft')
    : EDITABLE_REWARD_STATUSES;

  return (
    <>
      <Field>
        <FieldLabel>{__('Status', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Controller
            control={control}
            name="status"
            render={({ field }) => (
              // Re-clicking the active item yields an empty string; never write that back.
              <Segmented
                shape="square"
                value={field.value}
                onValueChange={(value) => value && field.onChange(value)}
                className="w-full sm:w-1/2"
              >
                {statuses.map((status) => (
                  <SegmentedItem key={status} value={status} className="flex-1">
                    {statusLabel(status)}
                  </SegmentedItem>
                ))}
              </Segmented>
            )}
          />
        </FieldContent>
      </Field>

      <ScheduleFields
        className="sm:grid sm:grid-cols-2 sm:[&>*:last-child]:col-span-2"
        startsAt={startsAt}
        endsAt={endsAt}
        onStartsAtChange={(next) => setValue('starts_at', next, { shouldDirty: true, shouldValidate: true })}
        onEndsAtChange={(next) => setValue('ends_at', next, { shouldDirty: true, shouldValidate: true })}
        endsAtError={formState.errors.ends_at}
      />
    </>
  );
}
