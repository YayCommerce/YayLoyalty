import { useMemo, useState } from 'react';
import { HTTPError } from 'ky';
import { ChevronLeft } from 'lucide-react';
import { FormProvider, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { parseWPRelativeDate } from '@/lib/helpers/format.helper';
import { useCampaignQuery, useCreateCampaignMutation, useUpdateCampaignMutation } from '@/lib/queries/campaign.queries';
import { hasPerformancePage } from '@/lib/schema/campaign-performance.schema';
import {
  Campaign,
  DEFAULT_CAMPAIGN_CONFIG,
  LiteCampaignTrigger,
  resolveCampaignDisplayStatus,
} from '@/lib/schema/campaign.schema';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import PageLayout from '@/components/layout/PageLayout';
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
  duplicateToFormValues,
  formValuesToCreateInput,
  formValuesToUpdateInput,
  synthesizeDraftCampaign,
} from './campaign-editor.form';
import { CampaignEditorSections } from './CampaignEditorSections';
import { CampaignEditorSkeleton } from './CampaignEditorSkeleton';
import { CampaignEditorSummaryRail } from './CampaignEditorSummaryRail';
import { useCampaignEditorSave } from './use-campaign-editor-save';

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

  if (campaignQuery.isPending) {
    return <CampaignEditorSkeleton />;
  }

  if (campaignQuery.isError) {
    if (campaignQuery.error instanceof HTTPError && campaignQuery.error.response.status === 404) {
      return <NotFoundPage />;
    }

    return (
      <PageLayout title={__('Campaign', 'yayloyalty')}>
        <ErrorState
          message={__('Could not load this campaign.', 'yayloyalty')}
          onRetry={() => campaignQuery.refetch()}
        />
      </PageLayout>
    );
  }

  return <CampaignEditorForm id={id} campaign={campaignQuery.data} />;
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

  const { handleSave, bypassNext, markDraftIntent, leaveDialogProps } = useCampaignEditorSave(
    form,
    async (values, isDraft) => {
      const input = formValuesToUpdateInput(values);

      try {
        const saved = await mutation.mutateAsync(isDraft ? { ...input, status: 'draft' } : input);
        form.reset(campaignToFormValues(saved));
        toast.success(__('Campaign saved.', 'yayloyalty'));
        return true;
      } catch (error) {
        await toastApiError(error, __('Could not save the campaign.', 'yayloyalty'));
        return false;
      }
    },
  );

  // The header renders from the loaded campaign, never form state; "Unsaved changes" says when the two differ.
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);

  return (
    <CampaignEditorContext.Provider value={{ mode: 'edit', campaign, trigger: campaign.trigger }}>
      <FormProvider {...form}>
        <form onSubmit={handleSave}>
          <CampaignEditorBackLink />

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
                      parseWPRelativeDate(campaign.starts_at),
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
                // type="button": this sits inside the form. Disabled mid-save, whose own redirect would race it.
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
            description={
              <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <Badge variant="outline">{triggerLabel(campaign.trigger)}</Badge>
                <span aria-hidden>·</span>
                <span className="font-mono text-xs">{campaign.slug}</span>
              </span>
            }
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

/** The row above both editors' titles: back to the campaign list. */
function CampaignEditorBackLink() {
  const navigate = useNavigate();

  return (
    <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
      <Button type="button" variant="link" className="h-auto gap-1 p-0!" onClick={() => navigate('/campaigns')}>
        <ChevronLeft className="size-3.5" />
        {__('Campaigns', 'yayloyalty')}
      </Button>
    </div>
  );
}

/**
 * Create-mode entry point for `campaigns/new/:templateId`, optionally `?from=<id>` for
 * CampaignTable's Duplicate action. Nothing is written until Save.
 */
export function CampaignCreator({ templateId, duplicateFromId }: { templateId: string; duplicateFromId?: number }) {
  // Memoised so CampaignCreatorForm's seed stays stable: findTemplate() builds a fresh object per call.
  const template = useMemo(() => findTemplate(templateId), [templateId]);
  // enabled: id > 0 inside the hook means an absent duplicateFromId never fetches.
  const sourceQuery = useCampaignQuery(duplicateFromId ?? 0);

  if (!template) {
    return <NotFoundPage />;
  }

  if (!duplicateFromId) {
    return <CampaignCreatorForm template={template} />;
  }

  if (sourceQuery.isPending) {
    return <CampaignEditorSkeleton />;
  }

  if (sourceQuery.isError) {
    if (sourceQuery.error instanceof HTTPError && sourceQuery.error.response.status === 404) {
      return <NotFoundPage />;
    }

    return (
      <PageLayout title={__('New campaign', 'yayloyalty')}>
        <ErrorState
          message={__('Could not load the campaign to duplicate.', 'yayloyalty')}
          onRetry={() => sourceQuery.refetch()}
        />
      </PageLayout>
    );
  }

  return <CampaignCreatorForm template={template} duplicateFrom={sourceQuery.data} />;
}

function CampaignCreatorForm({ template, duplicateFrom }: { template: CampaignTemplate; duplicateFrom?: Campaign }) {
  const navigate = useNavigate();
  const mutation = useCreateCampaignMutation();

  // Stable across this component's life: template and duplicateFrom are each fixed for one visit
  // to this route (a duplicate source is fetched once, not refetched mid-edit).
  const seed = useMemo<CampaignCreateSeed>(
    () =>
      duplicateFrom ? duplicateToFormValues(duplicateFrom) : { trigger: template.trigger, values: template.seed() },
    [template, duplicateFrom],
  );
  const draftCampaign = useMemo(() => synthesizeDraftCampaign(), []);

  const [trigger, setTrigger] = useState<LiteCampaignTrigger>(seed.trigger);

  // `values`, not defaultValues: matches CampaignEditorForm, though nothing here ever refetches.
  const form = useForm<CampaignFormValues>({
    resolver: campaignFormResolver,
    values: seed.values,
    resetOptions: { keepDirtyValues: true },
  });

  const { handleSave, markDraftIntent, leaveDialogProps } = useCampaignEditorSave(form, async (values, isDraft) => {
    const input = formValuesToCreateInput(values, trigger);

    try {
      await mutation.mutateAsync(isDraft ? { ...input, status: 'draft' } : input);
      toast.success(__('Campaign created.', 'yayloyalty'));
      return true;
    } catch (error) {
      await toastApiError(error, __('Could not create the campaign.', 'yayloyalty'));
      return false;
    }
  });

  /** A stale key from the old trigger would fail Campaign::config_is_valid() on save. */
  function handleTriggerChange(next: LiteCampaignTrigger) {
    setTrigger(next);
    form.setValue('config', structuredClone(DEFAULT_CAMPAIGN_CONFIG[next]), {
      shouldDirty: true,
      shouldValidate: true,
    });
    form.setValue('conditions', [], { shouldDirty: true, shouldValidate: true });
  }

  return (
    <CampaignEditorContext.Provider
      value={{ mode: 'create', campaign: draftCampaign, trigger, onTriggerChange: handleTriggerChange }}
    >
      <FormProvider {...form}>
        <form onSubmit={handleSave}>
          <CampaignEditorBackLink />

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
