import { useEffect, useMemo, useRef, useState } from 'react';
import { FormProvider, useForm, UseFormReturn } from 'react-hook-form';
import { Navigate, useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { isNotFoundError, toastApiError } from '@/lib/api/api';
import { storeCurrency } from '@/lib/helpers/format.helper';
import { useCreateRewardMutation, useRewardQuery, useUpdateRewardMutation } from '@/lib/queries/reward.queries';
import { CatalogRewardType, resolveRewardDisplayStatus, Reward } from '@/lib/schema/reward.schema';
import { useEditorSave } from '@/hooks/useEditorSave';
import { Badge } from '@/components/ui/badge';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { CampaignStatusBadge } from '@/pro/campaigns/CampaignStatusBadge';
import { RewardArchiveDialog } from '../archive/RewardArchiveDialog';
import {
  CREATABLE_REWARD_TYPES,
  defaultRewardName,
  isCreatableRewardType,
  templateTitle,
  typeLabel,
} from '../reward-labels';
import {
  duplicateToFormValues,
  formValuesToCreateInput,
  formValuesToUpdateInput,
  rewardCreateSeed,
  rewardFormResolver,
  RewardFormValues,
  rewardToFormValues,
} from './reward-editor.form';
import { RewardEditorModal, RewardEditorModalHeader, RewardEditorModalState } from './RewardEditorModal';
import { RewardEditorModalFooter } from './RewardEditorModalFooter';
import { RewardEditorSections } from './RewardEditorSections';

const EDIT_TITLE = () => __('Edit reward', 'yayloyalty');
const ADD_TITLE = () => __('Add a reward', 'yayloyalty');

/** Edit-mode entry for `rewards/:id/edit`: one modal, holding the loading and error states, then the form. */
export function RewardEditor({ id }: { id: number }) {
  return (
    <RewardEditorModal>
      <RewardEditorContent id={id} />
    </RewardEditorModal>
  );
}

function RewardEditorContent({ id }: { id: number }) {
  const rewardQuery = useRewardQuery(id);

  // An invalid id never enables the query, which would leave isPending true forever.
  if (!(id > 0) || (rewardQuery.isError && isNotFoundError(rewardQuery.error))) {
    return <RewardEditorModalState title={EDIT_TITLE()} error={__('This reward does not exist.', 'yayloyalty')} />;
  }

  if (rewardQuery.isError) {
    return (
      <RewardEditorModalState
        title={EDIT_TITLE()}
        error={__('Could not load this reward.', 'yayloyalty')}
        onRetry={() => rewardQuery.refetch()}
      />
    );
  }

  if (rewardQuery.isPending) {
    return <RewardEditorModalState title={EDIT_TITLE()} />;
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

  const { handleSave, bypassNext, markDraftIntent, leaveDialogProps } = useEditorSave(
    form,
    async (values, intent) => {
      try {
        const saved = await mutation.mutateAsync(formValuesToUpdateInput(values, intent));
        form.reset(rewardToFormValues(saved, storeCurrency()));
        toast.success(__('Reward saved.', 'yayloyalty'));
        return true;
      } catch (error) {
        await toastApiError(error, __('Could not save the reward.', 'yayloyalty'));
        return false;
      }
    },
    { listPath: '/rewards', onInvalid: toastInvalidFields },
  );

  function leaveAfterArchiveOrDelete() {
    // Unsaved edits belong to a reward that has just left the catalog; the guard would only nag.
    bypassNext();
    navigate('/rewards');
  }

  return (
    <>
      <RewardEditorFrame
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
        typePicker={
          <Badge variant="outline" className="self-start">
            {typeLabel(reward.type)}
          </Badge>
        }
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
  if (!isCreatableRewardType(type)) {
    return <Navigate to="/rewards" replace />;
  }

  return (
    <RewardEditorModal>
      <RewardCreatorContent type={type} duplicateFromId={duplicateFromId} />
    </RewardEditorModal>
  );
}

function RewardCreatorContent({ type, duplicateFromId }: { type: CatalogRewardType; duplicateFromId?: number }) {
  // enabled: id > 0 inside the hook means an absent duplicateFromId never fetches.
  const sourceQuery = useRewardQuery(duplicateFromId ?? 0);

  if (!duplicateFromId) {
    return <RewardCreatorForm type={type} />;
  }

  if (sourceQuery.isError) {
    return (
      <RewardEditorModalState
        title={ADD_TITLE()}
        error={__('Could not load the reward to duplicate.', 'yayloyalty')}
        onRetry={() => sourceQuery.refetch()}
      />
    );
  }

  if (sourceQuery.isPending) {
    return <RewardEditorModalState title={ADD_TITLE()} />;
  }

  // A hand-edited URL naming another type would seed a form whose fields don't match the copied config.
  if (sourceQuery.data.type !== type) {
    return <Navigate to="/rewards" replace />;
  }

  return <RewardCreatorForm type={type} duplicateFrom={sourceQuery.data} />;
}

function RewardCreatorForm({ type, duplicateFrom }: { type: CatalogRewardType; duplicateFrom?: Reward }) {
  const navigate = useNavigate();
  const mutation = useCreateRewardMutation();

  // Changes only when the type is switched in the modal; a duplicate never switches type.
  const seed = useMemo(
    () =>
      duplicateFrom ? duplicateToFormValues(duplicateFrom, storeCurrency()) : rewardCreateSeed(type, storeCurrency()),
    [type, duplicateFrom],
  );
  const resolver = useMemo(() => rewardFormResolver(type), [type]);
  const form = useForm<RewardFormValues>({ resolver, values: seed, resetOptions: { keepDirtyValues: true } });
  const autoName = useRef(seed.name);

  // Switching type in the modal keeps the modal mounted and starts the new type's form afresh, edits included.
  useEffect(() => {
    autoName.current = seed.name;
    form.reset(seed);
  }, [form, seed]);

  // A new reward's name follows its offer until the merchant types their own; a duplicate keeps "(copy)".
  useEffect(() => {
    if (duplicateFrom) {
      return;
    }

    const subscription = form.watch((values, { name }) => {
      if (name !== 'config' || form.getValues('name') !== autoName.current) {
        return;
      }

      autoName.current = defaultRewardName(type, form.getValues('config'));
      form.setValue('name', autoName.current);
    });

    return () => subscription.unsubscribe();
  }, [form, type, duplicateFrom]);

  const { handleSave, markDraftIntent, leaveDialogProps } = useEditorSave(
    form,
    async (values, intent) => {
      try {
        await mutation.mutateAsync(formValuesToCreateInput(values, type, intent));
        // The baseline must stop counting as unsaved, or closing re-trips the leave guard.
        form.reset(values);
        toast.success(__('Reward created.', 'yayloyalty'));
        return true;
      } catch (error) {
        await toastApiError(error, __('Could not create the reward.', 'yayloyalty'));
        return false;
      }
    },
    { listPath: '/rewards', onInvalid: toastInvalidFields },
  );

  return (
    <>
      <RewardEditorFrame
        form={form}
        onSubmit={handleSave}
        title={ADD_TITLE()}
        reward={null}
        type={type}
        typePicker={
          // A duplicate keeps its source's type: its copied config only fits that type's fields.
          duplicateFrom ? (
            <Badge variant="outline" className="self-start">
              {templateTitle(type)}
            </Badge>
          ) : (
            <Segmented
              shape="square"
              value={type}
              onValueChange={(next) => next && navigate(`/rewards/new/${next}`, { replace: true })}
              className="w-full"
            >
              {CREATABLE_REWARD_TYPES.map((option) => (
                <SegmentedItem key={option} value={option} className="flex-1">
                  {templateTitle(option)}
                </SegmentedItem>
              ))}
            </Segmented>
          )
        }
        busy={mutation.isPending}
        onDraftIntent={markDraftIntent}
        onCancel={() => navigate('/rewards')}
      />

      <UnsavedChangeDialog {...leaveDialogProps} />
    </>
  );
}

/** Both modes' rows inside the modal: header, the scrolling sections, then the preview and actions. */
function RewardEditorFrame({
  form,
  onSubmit,
  title,
  reward,
  type,
  typePicker,
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
  typePicker: React.ReactNode;
  busy: boolean;
  onDraftIntent: () => void;
  onArchive?: () => void;
  onCancel: () => void;
}) {
  return (
    <>
      <RewardEditorModalHeader title={title} />
      <FormProvider {...form}>
        {/* `contents`: the body and the footer stay rows of the modal's grid. */}
        <form onSubmit={onSubmit} className="contents">
          <div className="overflow-y-auto px-6 py-5">
            <RewardEditorSections
              type={type}
              typePicker={typePicker}
              couponCode={reward?.coupon_code}
              isBuiltin={reward?.is_builtin ?? false}
            />
          </div>
          <RewardEditorModalFooter
            reward={reward}
            busy={busy || form.formState.isSubmitting}
            onDraftIntent={onDraftIntent}
            onArchive={onArchive}
            onCancel={onCancel}
          />
        </form>
      </FormProvider>
    </>
  );
}

/** The buttons sit below a long form, often far from the field that failed. */
function toastInvalidFields() {
  toast.error(__('Please fix the highlighted fields before saving.', 'yayloyalty'));
}
