import { useState } from 'react';
import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import type { EditorSaveIntent } from '@/hooks/useEditorSave';
import { Button, LoadingButton } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ScheduleFields } from '@/components/ui/custom/schedule-fields';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { statusLabel } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';

// A draft or archived campaign selects neither; saving keeps its status until one is picked.
const CAMPAIGN_STATUS_OPTIONS = ['active', 'paused'] as const;

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
  const { mode, campaign } = useCampaignEditorContext();

  const busy = mutation.isPending || formState.isSubmitting;
  // Editing with nothing changed, Save is a no-op; Save as draft still drafts a campaign that isn't one yet.
  const unchanged = mode === 'edit' && !formState.isDirty;
  // Which save was pressed, so only that button spins. The leave dialog's save counts as Save.
  const [pressed, setPressed] = useState<EditorSaveIntent>('save');

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
        <LoadingButton
          type="submit"
          loading={busy && pressed === 'save'}
          disabled={busy || unchanged}
          onClick={() => setPressed('save')}
        >
          {__('Save campaign', 'yayloyalty')}
        </LoadingButton>
        <LoadingButton
          type="submit"
          variant="outline"
          data-intent="draft"
          loading={busy && pressed === 'draft'}
          disabled={busy || (unchanged && campaign.status === 'draft')}
          onClick={() => {
            setPressed('draft');
            onDraftIntent();
          }}
        >
          {__('Save as draft', 'yayloyalty')}
        </LoadingButton>
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

  return (
    <Field>
      <FieldLabel>{__('Status', 'yayloyalty')}</FieldLabel>
      <FieldContent>
        <Controller
          control={control}
          name="status"
          render={({ field }) => (
            // Re-clicking the active item yields an empty string; never write that back.
            <Segmented value={field.value} onValueChange={(value) => value && field.onChange(value)}>
              {CAMPAIGN_STATUS_OPTIONS.map((status) => (
                <SegmentedItem key={status} value={status}>
                  {statusLabel(status)}
                </SegmentedItem>
              ))}
            </Segmented>
          )}
        />
      </FieldContent>
    </Field>
  );
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
