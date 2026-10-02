import { useMemo, useState } from 'react';
import { HTTPError } from 'ky';
import { FormProvider, useForm, UseFormReturn } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useCreateRewardMutation, useRewardQuery, useUpdateRewardMutation } from '@/lib/queries/reward.queries';
import {
  CatalogRewardType,
  isCatalogRewardType,
  normalizeRewardConditions,
  resolveRewardDisplayStatus,
  Reward,
} from '@/lib/schema/reward.schema';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import NotFoundPage from '@/pages/404';
import { CampaignStatusBadge } from '@/pro/campaigns/CampaignStatusBadge';
import { RewardArchiveDialog } from '../archive/RewardArchiveDialog';
import { templateTitle, typeLabel } from '../reward-labels';
import { RewardsBackLink } from '../RewardsBackLink';
import {
  duplicateToFormValues,
  formValuesToCreateInput,
  formValuesToUpdateInput,
  rewardCreateSeed,
  rewardEditorActions,
  rewardFormResolver,
  RewardFormValues,
  rewardToFormValues,
} from './reward-editor.form';
import { RewardEditorSections } from './RewardEditorSections';
import { useRewardEditorSave } from './use-reward-editor-save';

function storeCurrency(): string {
  return window.yayLoyaltyMeta.wcMeta.currency_data.currency;
}

/** Edit-mode entry for `rewards/:id/edit`: every non-content state, then the form. */
export function RewardEditor({ id }: { id: number }) {
  const rewardQuery = useRewardQuery(id);

  // An invalid id never enables the query, which would leave isPending true forever.
  if (!(id > 0)) {
    return <NotFoundPage />;
  }

  if (rewardQuery.isPending) {
    return <RewardEditorSkeleton />;
  }

  if (rewardQuery.isError) {
    if (rewardQuery.error instanceof HTTPError && rewardQuery.error.response.status === 404) {
      return <NotFoundPage />;
    }

    return (
      <PageLayout title={__('Reward', 'yayloyalty')}>
        <ErrorState message={__('Could not load this reward.', 'yayloyalty')} onRetry={() => rewardQuery.refetch()} />
      </PageLayout>
    );
  }

  return <RewardEditorForm reward={rewardQuery.data} />;
}

function RewardEditorForm({ reward }: { reward: Reward }) {
  const navigate = useNavigate();
  const mutation = useUpdateRewardMutation(reward.id);
  const [archiveOpen, setArchiveOpen] = useState(false);

  const formValues = useMemo(() => rewardToFormValues(reward, storeCurrency()), [reward]);
  const resolver = useMemo(() => rewardFormResolver(reward.type), [reward.type]);

  // `values`, not defaultValues + reset(): a refetch re-seeds the form, and keepDirtyValues spares a field mid-edit.
  const form = useForm<RewardFormValues>({ resolver, values: formValues, resetOptions: { keepDirtyValues: true } });

  const { handleSave, bypassNext, markDraftIntent, leaveDialogProps } = useRewardEditorSave(
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

  if (!isCatalogRewardType(type)) {
    return <NotFoundPage />;
  }

  if (!duplicateFromId) {
    return <RewardCreatorForm type={type} />;
  }

  if (sourceQuery.isPending) {
    return <RewardEditorSkeleton />;
  }

  if (sourceQuery.isError) {
    if (sourceQuery.error instanceof HTTPError && sourceQuery.error.response.status === 404) {
      return <NotFoundPage />;
    }

    return (
      <PageLayout title={__('New reward', 'yayloyalty')}>
        <ErrorState
          message={__('Could not load the reward to duplicate.', 'yayloyalty')}
          onRetry={() => sourceQuery.refetch()}
        />
      </PageLayout>
    );
  }

  // A hand-edited URL naming another type would seed a form whose fields don't match the copied config.
  if (sourceQuery.data.type !== type) {
    return <NotFoundPage />;
  }

  return <RewardCreatorForm type={type} duplicateFrom={sourceQuery.data} />;
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

  const { handleSave, markDraftIntent, leaveDialogProps } = useRewardEditorSave(form, async (values, intent) => {
    try {
      const sourceConditions = duplicateFrom ? normalizeRewardConditions(duplicateFrom.conditions) : [];
      await mutation.mutateAsync(formValuesToCreateInput(values, type, intent, sourceConditions));
      // The created row lives in the list now; this form's baseline must stop counting as unsaved.
      form.reset(values);
      toast.success(__('Reward created.', 'yayloyalty'));
      return true;
    } catch (error) {
      await toastApiError(error, __('Could not create the reward.', 'yayloyalty'));
      return false;
    }
  });

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

/** Shared page frame for both modes: back link, header, sections on the left, the four actions on the right. */
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
        <RewardsBackLink />

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
 * The side area: Save, Save draft, Archive, Cancel and nothing else. Both
 * saves are type="submit" so the resolver runs for a draft too; data-intent
 * says which was pressed.
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
  const { canSaveDraft, showArchive, isArchiveDisabled } = rewardEditorActions(reward);

  return (
    <div className="flex w-full flex-col gap-2 lg:sticky lg:top-6 lg:w-72 lg:shrink-0">
      <Button type="submit" disabled={busy}>
        {busy ? __('Saving…', 'yayloyalty') : __('Save reward', 'yayloyalty')}
      </Button>
      {canSaveDraft && (
        <Button type="submit" variant="outline" data-intent="draft" disabled={busy} onClick={onDraftIntent}>
          {__('Save as draft', 'yayloyalty')}
        </Button>
      )}
      {showArchive && onArchive && (
        // type="button": archiving is the dialog's decision, never a form save.
        <Button type="button" variant="destructive" disabled={busy || isArchiveDisabled} onClick={onArchive}>
          {__('Archive reward', 'yayloyalty')}
        </Button>
      )}
      {showArchive && isArchiveDisabled && (
        <p className="text-muted-foreground text-center text-xs">
          {__('The default reward can be switched off but not archived.', 'yayloyalty')}
        </p>
      )}
      <Button type="button" variant="link" disabled={busy} onClick={onCancel}>
        {__('Cancel', 'yayloyalty')}
      </Button>
    </div>
  );
}

function RewardEditorSkeleton() {
  return (
    <PageLayout className="max-w-6xl" title={<Skeleton className="h-7 w-48" />}>
      <div className="flex flex-col gap-5 lg:flex-row">
        <div className="flex flex-1 flex-col gap-4">
          <Skeleton className="h-32 w-full" />
          <Skeleton className="h-64 w-full" />
          <Skeleton className="h-48 w-full" />
        </div>
        <Skeleton className="h-40 w-full lg:w-72" />
      </div>
    </PageLayout>
  );
}
