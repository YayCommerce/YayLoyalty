import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { CampaignStatus } from '@/lib/schema/campaign.schema';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ScheduleFields } from '@/components/ui/custom/schedule-fields';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';

/**
 * The right rail: status and schedule in one card, then the actions. Both save buttons are
 * type="submit", so the resolver runs for a draft save too; data-intent says which was pressed.
 */
export function CampaignEditorSummaryRail({
  mutation,
  onCancel,
  onDraftIntent,
  onArchive,
}: {
  /** Only isPending is read here; the create and update mutations differ in their variables type, which this never needs. */
  mutation: { isPending: boolean };
  onCancel: () => void;
  /** Omitted where archiving is not offered. */
  onArchive?: () => void;
  /** Fallback for Safari < 15.4, which lacks SubmitEvent.submitter. */
  onDraftIntent: () => void;
}) {
  const { formState } = useFormContext<CampaignFormValues>();
  const { campaign } = useCampaignEditorContext();

  const busy = mutation.isPending || formState.isSubmitting;

  return (
    <div className="flex w-full flex-col gap-4 lg:sticky lg:top-6 lg:w-85 lg:shrink-0">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{__('Status and schedule', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <CampaignEditorStatus />
          <CampaignEditorSchedule />
        </CardContent>
      </Card>

      <div className="flex flex-col gap-2">
        <Button type="submit" disabled={busy}>
          {mutation.isPending ? __('Saving…', 'yayloyalty') : __('Save campaign', 'yayloyalty')}
        </Button>
        <Button type="submit" variant="outline" data-intent="draft" disabled={busy} onClick={onDraftIntent}>
          {__('Save as draft', 'yayloyalty')}
        </Button>
        <div className="flex items-center justify-center gap-2">
          {/* type="button": archiving is the dialog's decision, never a form save. */}
          {onArchive && !campaign.is_builtin && campaign.status !== 'archived' && (
            <Button
              type="button"
              variant="link"
              className="text-destructive hover:text-destructive-accent"
              disabled={busy}
              onClick={onArchive}
            >
              {__('Archive campaign', 'yayloyalty')}
            </Button>
          )}
          <Button type="button" variant="link" disabled={busy} onClick={onCancel}>
            {__('Cancel', 'yayloyalty')}
          </Button>
        </div>
      </div>
    </div>
  );
}

function CampaignEditorStatus() {
  const { control } = useFormContext<CampaignFormValues>();
  const { campaign } = useCampaignEditorContext();
  // Off goes back to the saved status, so switching a draft on and off again never makes it "paused".
  const offStatus: CampaignStatus = campaign.status === 'active' ? 'paused' : campaign.status;

  return (
    <Controller
      control={control}
      name="status"
      render={({ field }) => (
        <Field orientation="horizontal">
          <FieldContent>
            <FieldLabel htmlFor="campaign-enabled">{__('Enabled', 'yayloyalty')}</FieldLabel>
            <FieldDescription>{statusHint(field.value)}</FieldDescription>
          </FieldContent>
          <Switch
            id="campaign-enabled"
            checked={field.value === 'active'}
            onCheckedChange={(isOn) => field.onChange(isOn ? 'active' : offStatus)}
          />
        </Field>
      )}
    />
  );
}

function statusHint(status: CampaignStatus): string {
  switch (status) {
    case 'active':
      return __('Customers earn from this campaign while its schedule allows.', 'yayloyalty');
    case 'paused':
      return __('Paused: customers don’t earn from it until you switch it back on.', 'yayloyalty');
    case 'draft':
      return __('Draft: not live until you switch it on.', 'yayloyalty');
    case 'archived':
      return __('Archived: switch it on to restore it.', 'yayloyalty');
  }
}

function CampaignEditorSchedule() {
  const { control, setValue, formState } = useFormContext<CampaignFormValues>();
  const startsAt = useWatch({ control, name: 'starts_at' });
  const endsAt = useWatch({ control, name: 'ends_at' });

  return (
    <ScheduleFields
      startsAt={startsAt}
      endsAt={endsAt}
      onStartsAtChange={(next) => setValue('starts_at', next, { shouldDirty: true, shouldValidate: true })}
      onEndsAtChange={(next) => setValue('ends_at', next, { shouldDirty: true, shouldValidate: true })}
      endsAtError={formState.errors.ends_at}
    />
  );
}
