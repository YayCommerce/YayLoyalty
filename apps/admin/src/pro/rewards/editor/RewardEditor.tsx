import { useMemo, useState } from 'react';
import { Controller, FormProvider, useForm, useFormContext, UseFormReturn, useWatch } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { storeCurrency } from '@/lib/helpers/format.helper';
import { useCreateRewardMutation, useRewardQuery, useUpdateRewardMutation } from '@/lib/queries/reward.queries';
import {
  CatalogRewardType,
  normalizeRewardConditions,
  resolveRewardDisplayStatus,
  Reward,
} from '@/lib/schema/reward.schema';
import { EditorSaveIntent, useEditorSave } from '@/hooks/useEditorSave';
import { Badge } from '@/components/ui/badge';
import { Button, LoadingButton } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BackLink } from '@/components/ui/custom/back-link';
import { ScheduleFields } from '@/components/ui/custom/schedule-fields';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import PageLayout from '@/components/layout/PageLayout';
import { resolveQueryLoad } from '@/components/layout/resolve-query-load';
import NotFoundPage from '@/pages/404';
import { statusLabel } from '@/pro/campaigns/campaign-labels';
import { CampaignStatusBadge } from '@/pro/campaigns/CampaignStatusBadge';
import { RewardArchiveDialog } from '../archive/RewardArchiveDialog';
import { isCreatableRewardType, templateTitle, typeLabel } from '../reward-labels';
import {
  duplicateToFormValues,
  EDITABLE_REWARD_STATUSES,
  formValuesToCreateInput,
  formValuesToUpdateInput,
  rewardCreateSeed,
  rewardEditorActions,
  rewardFormResolver,
  RewardFormValues,
  rewardToFormValues,
} from './reward-editor.form';
import { RewardEditorSections } from './RewardEditorSections';
import { RewardEditorSkeleton } from './RewardEditorSkeleton';

/** Edit-mode entry for `rewards/:id/edit`: every non-content state, then the form. */
export function RewardEditor({ id }: { id: number }) {
  const rewardQuery = useRewardQuery(id);

  // An invalid id never enables the query, which would leave isPending true forever.
  if (!(id > 0)) {
    return <NotFoundPage />;
  }

  const loaded = resolveQueryLoad(rewardQuery, {
    skeleton: <RewardEditorSkeleton />,
    title: __('Reward', 'yayloyalty'),
    errorMessage: __('Could not load this reward.', 'yayloyalty'),
  });

  return 'screen' in loaded ? loaded.screen : <RewardEditorForm reward={loaded.data} />;
}

function RewardEditorForm({ reward }: { reward: Reward }) {
  const navigate = useNavigate();
  const mutation = useUpdateRewardMutation(reward.id);
  const [archiveOpen, setArchiveOpen] = useState(false);

  const formValues = useMemo(() => rewardToFormValues(reward, storeCurrency()), [reward]);
  const resolver = useMemo(() => rewardFormResolver(reward.type), [reward.type]);

  // `values`, not defaultValues + reset(): a refetch re-seeds the form, and keepDirtyValues spares a field mid-edit.
  const form = useForm<RewardFormValues>({ resolver, values: formValues, resetOptions: { keepDirtyValues: true } });

  const { handleSave, bypassNext, markDraftIntent, leaveDialogProps } = useEditorSave(
    form,
    async (values, intent) => {
      try {
        const saved = await mutation.mutateAsync(formValuesToUpdateInput(values, reward, intent));
        form.reset(rewardToFormValues(saved, storeCurrency()));
        toast.success(__('Reward saved.', 'yayloyalty'));
        return true;
      } catch (error) {
        await toastApiError(error, __('Could not save the reward.', 'yayloyalty'));
        return false;
      }
    },
    // Saving an existing reward stays on it, as creating one opens its editor.
    { listPath: null, onInvalid: toastInvalidFields },
  );

  function leaveAfterArchiveOrDelete() {
    // Unsaved edits belong to a reward that has just left the catalog; the guard would only nag.
    bypassNext();
    navigate('/rewards');
  }

  return (
    <>
      <RewardEditorLayout
        form={form}
        onSubmit={handleSave}
        title={
          <span className="flex flex-wrap items-center gap-2">
            {reward.name}
            {reward.is_builtin && (
              <Badge variant="outline" className="uppercase">
                {__('Default', 'yayloyalty')}
              </Badge>
            )}
            <CampaignStatusBadge status={resolveRewardDisplayStatus(reward.status, reward.starts_at, reward.ends_at)} />
            {form.formState.isDirty && (
              <span className="text-muted-foreground text-sm font-normal">{__('Unsaved changes', 'yayloyalty')}</span>
            )}
          </span>
        }
        reward={reward}
        type={reward.type}
        busy={mutation.isPending}
        onDraftIntent={markDraftIntent}
        onArchive={() => setArchiveOpen(true)}
        onCancel={() => navigate('/rewards')}
      />

      <UnsavedChangeDialog {...leaveDialogProps} />

      {archiveOpen && (
        <RewardArchiveDialog
          reward={reward}
          onClose={() => setArchiveOpen(false)}
          onDone={leaveAfterArchiveOrDelete}
          discardsUnsavedChanges
        />
      )}
    </>
  );
}

/**
 * Create-mode entry for `rewards/new/:type`; nothing is written until Save or
 * Save draft. `duplicateFromId` is the table's Duplicate action: the form
 * starts from that reward instead of the type's seed.
 */
export function RewardCreator({ type, duplicateFromId }: { type: string; duplicateFromId?: number }) {
  // enabled: id > 0 inside the hook means an absent duplicateFromId never fetches.
  const sourceQuery = useRewardQuery(duplicateFromId ?? 0);

  if (!isCreatableRewardType(type)) {
    return <NotFoundPage />;
  }

  if (!duplicateFromId) {
    return <RewardCreatorForm type={type} />;
  }

  const loaded = resolveQueryLoad(sourceQuery, {
    skeleton: <RewardEditorSkeleton />,
    title: __('New reward', 'yayloyalty'),
    errorMessage: __('Could not load the reward to duplicate.', 'yayloyalty'),
  });

  if ('screen' in loaded) {
    return loaded.screen;
  }

  // A hand-edited URL naming another type would seed a form whose fields don't match the copied config.
  if (loaded.data.type !== type) {
    return <NotFoundPage />;
  }

  return <RewardCreatorForm type={type} duplicateFrom={loaded.data} />;
}

function RewardCreatorForm({ type, duplicateFrom }: { type: CatalogRewardType; duplicateFrom?: Reward }) {
  const navigate = useNavigate();
  const mutation = useCreateRewardMutation();

  // Stable for this visit: type and duplicateFrom are each fixed while the form is mounted.
  const seed = useMemo(
    () =>
      duplicateFrom ? duplicateToFormValues(duplicateFrom, storeCurrency()) : rewardCreateSeed(type, storeCurrency()),
    [type, duplicateFrom],
  );
  const resolver = useMemo(() => rewardFormResolver(type), [type]);
  const form = useForm<RewardFormValues>({ resolver, values: seed, resetOptions: { keepDirtyValues: true } });

  const { handleSave, markDraftIntent, leaveDialogProps } = useEditorSave(
    form,
    async (values, intent) => {
      try {
        const sourceConditions = duplicateFrom ? normalizeRewardConditions(duplicateFrom.conditions) : [];
        const created = await mutation.mutateAsync(formValuesToCreateInput(values, type, intent, sourceConditions));
        // The baseline must stop counting as unsaved, or the redirect re-trips the leave guard.
        form.reset(values);
        toast.success(__('Reward created.', 'yayloyalty'));
        // Replaces the create URL, so Back never reopens a blank form for a reward that now exists.
        return `/rewards/${created.id}/edit`;
      } catch (error) {
        await toastApiError(error, __('Could not create the reward.', 'yayloyalty'));
        return false;
      }
    },
    { listPath: '/rewards', onInvalid: toastInvalidFields },
  );

  return (
    <>
      <RewardEditorLayout
        form={form}
        onSubmit={handleSave}
        title={sprintf(
          /* translators: %s: the reward type's title, e.g. "Free shipping". */
          __('New reward: %s', 'yayloyalty'),
          templateTitle(type),
        )}
        reward={null}
        type={type}
        busy={mutation.isPending}
        onDraftIntent={markDraftIntent}
        onCancel={() => navigate('/rewards')}
      />

      <UnsavedChangeDialog {...leaveDialogProps} />
    </>
  );
}

/** Shared page frame for both modes: back link, header, sections on the left, status, schedule and actions on the right. */
function RewardEditorLayout({
  form,
  onSubmit,
  title,
  reward,
  type,
  busy,
  onDraftIntent,
  onArchive,
  onCancel,
}: {
  form: UseFormReturn<RewardFormValues>;
  onSubmit: () => void;
  title: React.ReactNode;
  /** Null while creating. */
  reward: Reward | null;
  type: Reward['type'];
  busy: boolean;
  onDraftIntent: () => void;
  onArchive?: () => void;
  onCancel: () => void;
}) {
  return (
    <FormProvider {...form}>
      <form onSubmit={onSubmit}>
        <BackLink to="/rewards" label={__('Rewards', 'yayloyalty')} />

        <PageLayout
          className="max-w-6xl"
          title={title}
          description={<Badge variant="outline">{typeLabel(type)}</Badge>}
        >
          <div className="flex flex-col items-start gap-5 lg:flex-row">
            <div className="min-w-0 flex-1">
              <RewardEditorSections type={type} />
            </div>

            <RewardEditorActions
              reward={reward}
              busy={busy || form.formState.isSubmitting}
              onDraftIntent={onDraftIntent}
              onArchive={onArchive}
              onCancel={onCancel}
            />
          </div>
        </PageLayout>
      </form>
    </FormProvider>
  );
}

/**
 * The side area: status and schedule in one card, then Save, Save draft,
 * Archive and Cancel. Both saves are type="submit" so the resolver runs for a
 * draft too; data-intent says which was pressed.
 */
function RewardEditorActions({
  reward,
  busy,
  onDraftIntent,
  onArchive,
  onCancel,
}: {
  reward: Reward | null;
  busy: boolean;
  onDraftIntent: () => void;
  onArchive?: () => void;
  onCancel: () => void;
}) {
  const { formState } = useFormContext<RewardFormValues>();
  const { canSave, canSaveDraft, showArchive } = rewardEditorActions(reward, formState.isDirty);
  // Which save was pressed, so only that button spins. The leave dialog's save counts as Save.
  const [pressed, setPressed] = useState<EditorSaveIntent>('save');

  return (
    <div className="flex w-full flex-col gap-4 lg:sticky lg:top-6 lg:w-85 lg:shrink-0">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{__('Status and schedule', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <RewardEditorStatus isBuiltin={reward?.is_builtin ?? false} />
          <RewardEditorSchedule />
        </CardContent>
      </Card>

      <div className="flex flex-col gap-2">
        <LoadingButton
          type="submit"
          loading={busy && pressed === 'save'}
          disabled={busy || !canSave}
          onClick={() => setPressed('save')}
        >
          {__('Save reward', 'yayloyalty')}
        </LoadingButton>
        {canSaveDraft && (
          <LoadingButton
            type="submit"
            variant="outline"
            data-intent="draft"
            loading={busy && pressed === 'draft'}
            disabled={busy || !canSave}
            onClick={() => {
              setPressed('draft');
              onDraftIntent();
            }}
          >
            {__('Save as draft', 'yayloyalty')}
          </LoadingButton>
        )}
        <div className="flex items-center justify-center gap-2">
          {showArchive && onArchive && (
            // type="button": archiving is the dialog's decision, never a form save.
            <Button
              type="button"
              variant="link"
              className="text-destructive hover:text-destructive-accent"
              disabled={busy}
              onClick={onArchive}
            >
              {__('Archive reward', 'yayloyalty')}
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

function RewardEditorStatus({ isBuiltin }: { isBuiltin: boolean }) {
  const { control } = useFormContext<RewardFormValues>();
  // The built-in is switched off by pausing, never drafted.
  const statuses = isBuiltin
    ? EDITABLE_REWARD_STATUSES.filter((status) => status !== 'draft')
    : EDITABLE_REWARD_STATUSES;

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
              {statuses.map((status) => (
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

function RewardEditorSchedule() {
  const { control, setValue, formState } = useFormContext<RewardFormValues>();
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

/** The buttons sit in the side area, often far from the field that failed. */
function toastInvalidFields() {
  toast.error(__('Please fix the highlighted fields before saving.', 'yayloyalty'));
}
