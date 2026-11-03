import { useMemo, useState } from 'react';
import { FieldErrors, FormProvider, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { formatWPRelativeDate } from '@/lib/helpers/format.helper';
import { useCampaignQuery, useCreateCampaignMutation, useUpdateCampaignMutation } from '@/lib/queries/campaign.queries';
import { hasPerformancePage } from '@/lib/schema/campaign-performance.schema';
import { Campaign, resolveDisplayStatus } from '@/lib/schema/campaign.schema';
import { useEditorSave } from '@/hooks/useEditorSave';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { BackLink } from '@/components/ui/custom/back-link';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import PageLayout from '@/components/layout/PageLayout';
import { resolveQueryLoad } from '@/components/layout/resolve-query-load';
import NotFoundPage from '@/pages/404';
import { CampaignTemplate, findTemplate } from '../add/campaign-templates';
import { CampaignArchiveDialog } from '../archive/CampaignArchiveDialog';
import { triggerLabel } from '../campaign-labels';
import { CampaignStatusBadge } from '../CampaignStatusBadge';
import {
  CampaignCreateSeed,
  CampaignEditorContext,
  campaignFormResolver,
  CampaignFormValues,
  campaignToFormValues,
  formValuesToCreateInput,
  formValuesToUpdateInput,
  synthesizeDraftCampaign,
} from './campaign-editor.form';
import { CampaignEditorSections } from './CampaignEditorSections';
import { CampaignEditorSkeleton } from './CampaignEditorSkeleton';
import { CampaignEditorSummaryRail } from './CampaignEditorSummaryRail';

/**
 * The campaign editor: form provider, layout and every non-content state. The sections render
 * via CampaignEditorSections.
 */
export function CampaignEditor({ id }: { id: number }) {
  const campaignQuery = useCampaignQuery(id);

  // An invalid id never enables the query, which would leave isPending true forever.
  if (!(id > 0)) {
    return <NotFoundPage />;
  }

  const loaded = resolveQueryLoad(campaignQuery, {
    skeleton: <CampaignEditorSkeleton />,
    title: __('Campaign', 'yayloyalty'),
    errorMessage: __('Could not load this campaign.', 'yayloyalty'),
  });

  return 'screen' in loaded ? loaded.screen : <CampaignEditorForm id={id} campaign={loaded.data} />;
}

function CampaignEditorForm({ id, campaign }: { id: number; campaign: Campaign }) {
  const navigate = useNavigate();
  const mutation = useUpdateCampaignMutation(id);
  const [archiveOpen, setArchiveOpen] = useState(false);

  const formValues = useMemo<CampaignFormValues>(() => campaignToFormValues(campaign), [campaign]);

  // `values`, not defaultValues + reset(): a refetch re-seeds the form on its own, and
  // keepDirtyValues stops it clobbering a field mid-edit.
  const form = useForm<CampaignFormValues>({
    resolver: campaignFormResolver,
    values: formValues,
    resetOptions: { keepDirtyValues: true },
  });

  const { handleSave, bypassNext, markDraftIntent, leaveDialogProps } = useEditorSave(
    form,
    async (values, intent) => {
      const input = formValuesToUpdateInput(values);

      try {
        const saved = await mutation.mutateAsync(intent === 'draft' ? { ...input, status: 'draft' } : input);
        form.reset(campaignToFormValues(saved));
        toast.success(__('Campaign saved.', 'yayloyalty'));
        return true;
      } catch (error) {
        await toastApiError(error, __('Could not save the campaign.', 'yayloyalty'));
        return false;
      }
    },
    // Save keeps the editor open; the reset above makes the saved values the new baseline.
    { listPath: null, onInvalid: toastInvalidConditions },
  );

  // The header renders from the loaded campaign, never form state; "Unsaved changes" says when the two differ.
  const displayStatus = resolveDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);

  return (
    <CampaignEditorContext.Provider value={{ mode: 'edit', campaign, trigger: campaign.trigger }}>
      <FormProvider {...form}>
        <form onSubmit={handleSave}>
          <BackLink to="/campaigns" label={__('Campaigns', 'yayloyalty')} />

          <PageLayout
            className="max-w-6xl"
            title={
              <span className="flex flex-wrap items-center gap-2">
                {campaign.name}
                {campaign.is_builtin && (
                  <Badge variant="outline" className="uppercase">
                    {__('Default', 'yayloyalty')}
                  </Badge>
                )}
                <CampaignStatusBadge status={displayStatus} />
                {displayStatus === 'scheduled' && campaign.starts_at && (
                  <span className="text-muted-foreground text-sm font-normal">
                    {sprintf(
                      /* translators: %s: relative time until the campaign starts, e.g. "in 3 days". */
                      __('starts %s', 'yayloyalty'),
                      formatWPRelativeDate(campaign.starts_at),
                    )}
                  </span>
                )}
                {form.formState.isDirty && (
                  <span className="text-muted-foreground text-sm font-normal">
                    {__('Unsaved changes', 'yayloyalty')}
                  </span>
                )}
              </span>
            }
            actions={
              hasPerformancePage(campaign.trigger) && (
                // type="button": this sits inside the form. Disabled mid-save, so it never leaves before the save lands.
                <Button
                  type="button"
                  variant="outline"
                  disabled={mutation.isPending || form.formState.isSubmitting}
                  onClick={() => navigate(`/campaigns/${id}/performance`)}
                >
                  {__('View performance', 'yayloyalty')}
                </Button>
              )
            }
            description={<Badge variant="outline">{triggerLabel(campaign.trigger)}</Badge>}
          >
            <div className="flex flex-col items-start gap-5 lg:flex-row">
              <div className="min-w-0 flex-1">
                <CampaignEditorSections />
              </div>

              <CampaignEditorSummaryRail
                mutation={mutation}
                onCancel={() => navigate('/campaigns')}
                onDraftIntent={markDraftIntent}
                onArchive={() => setArchiveOpen(true)}
              />
            </div>
          </PageLayout>
        </form>
      </FormProvider>

      <UnsavedChangeDialog {...leaveDialogProps} />

      {archiveOpen && (
        <CampaignArchiveDialog
          campaign={campaign}
          onClose={() => setArchiveOpen(false)}
          onDone={(action) => {
            // Unsaved edits belong to a campaign that has just left the list; the guard would only nag.
            if (action !== 'paused') {
              bypassNext();
              navigate('/campaigns');
            }
          }}
        />
      )}
    </CampaignEditorContext.Provider>
  );
}

/** Create-mode entry point for `campaigns/new/:trigger`. Nothing is written until Save. */
export function CampaignCreator({ trigger }: { trigger: string }) {
  // Memoised so CampaignCreatorForm's seed stays stable: findTemplate() builds a fresh object per call.
  const template = useMemo(() => findTemplate(trigger), [trigger]);

  if (!template) {
    return <NotFoundPage />;
  }

  return <CampaignCreatorForm template={template} />;
}

function CampaignCreatorForm({ template }: { template: CampaignTemplate }) {
  const navigate = useNavigate();
  const mutation = useCreateCampaignMutation();

  // Stable across this component's life: the template is fixed for one visit to this route.
  const seed = useMemo<CampaignCreateSeed>(() => ({ trigger: template.trigger, values: template.seed() }), [template]);
  const draftCampaign = useMemo(() => synthesizeDraftCampaign(), []);

  const trigger = seed.trigger;

  // `values`, not defaultValues: matches CampaignEditorForm, though nothing here ever refetches.
  const form = useForm<CampaignFormValues>({
    resolver: campaignFormResolver,
    values: seed.values,
    resetOptions: { keepDirtyValues: true },
  });

  const { handleSave, markDraftIntent, leaveDialogProps } = useEditorSave(
    form,
    async (values, intent) => {
      const input = formValuesToCreateInput(values, trigger);

      try {
        const created = await mutation.mutateAsync(intent === 'draft' ? { ...input, status: 'draft' } : input);
        toast.success(__('Campaign created.', 'yayloyalty'));
        // Replaces the create URL, so Back never reopens a blank form for a campaign that now exists.
        return `/campaigns/${created.id}/edit`;
      } catch (error) {
        await toastApiError(error, __('Could not create the campaign.', 'yayloyalty'));
        return false;
      }
    },
    { listPath: '/campaigns', onInvalid: toastInvalidConditions },
  );

  return (
    <CampaignEditorContext.Provider value={{ mode: 'create', campaign: draftCampaign, trigger }}>
      <FormProvider {...form}>
        <form onSubmit={handleSave}>
          <BackLink to="/campaigns" label={__('Campaigns', 'yayloyalty')} />

          <PageLayout
            className="max-w-6xl"
            title={__('New campaign', 'yayloyalty')}
            description={<Badge variant="outline">{triggerLabel(trigger)}</Badge>}
          >
            <div className="flex flex-col items-start gap-5 lg:flex-row">
              <div className="min-w-0 flex-1">
                <CampaignEditorSections />
              </div>

              <CampaignEditorSummaryRail
                mutation={mutation}
                onCancel={() => navigate('/campaigns')}
                onDraftIntent={markDraftIntent}
              />
            </div>
          </PageLayout>
        </form>
      </FormProvider>

      <UnsavedChangeDialog {...leaveDialogProps} />
    </CampaignEditorContext.Provider>
  );
}

/** ConditionRow shows each error inline; the toast makes a blocked save impossible to miss. */
function toastInvalidConditions(errors: FieldErrors<CampaignFormValues>) {
  if (errors.conditions) {
    toast.error(__('Please fix the highlighted conditions before saving.', 'yayloyalty'));
  }
}
