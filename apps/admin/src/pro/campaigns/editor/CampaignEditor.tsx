import { useMemo, useRef, useState } from 'react';
import { HTTPError } from 'ky';
import { ChevronLeft, Lock } from 'lucide-react';
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
  CampaignCreateInput,
  DEFAULT_CAMPAIGN_CONFIG,
  LiteCampaignTrigger,
  resolveCampaignDisplayStatus,
} from '@/lib/schema/campaign.schema';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/custom/error-state';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import NotFoundPage from '@/pages/404';
import { CampaignTemplate, findTemplate } from '../add/campaign-templates';
import { CampaignArchiveDialog } from '../archive/CampaignArchiveDialog';
import { statusLabel, triggerLabel } from '../campaign-labels';
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
import { CampaignEditorSummaryRail } from './CampaignEditorSummaryRail';

/**
 * The campaign editor: form provider, layout and every non-content state;
 * the sections render via CampaignEditorSections. First use of
 * react-hook-form + zodResolver in this app, on purpose: a variable-length
 * conditions array, dependent validation and a dirty flag for the leave
 * guard are what the library is for.
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

/** Shared by CampaignEditor's fetch and CampaignCreator's duplicate-source fetch. */
function CampaignEditorSkeleton() {
  return (
    <>
      <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
        <Skeleton className="h-4 w-24" />
      </div>

      <PageLayout
        className="max-w-6xl"
        title={<Skeleton className="h-8 w-64" />}
        description={<Skeleton className="h-4 w-40" />}
      >
        <div className="flex flex-col items-start gap-5 lg:flex-row">
          <div className="flex min-w-0 flex-1 flex-col gap-4">
            {EDITOR_SECTION_TITLES.map((title) => (
              <CampaignEditorSectionSkeleton key={title} />
            ))}
          </div>

          <div className="flex w-full flex-col gap-4 lg:w-85 lg:shrink-0">
            <Skeleton className="h-28 w-full" />
            <Skeleton className="h-36 w-full" />
            <Skeleton className="h-40 w-full" />
          </div>
        </div>
      </PageLayout>
    </>
  );
}

function CampaignEditorForm({ id, campaign }: { id: number; campaign: Campaign }) {
  const navigate = useNavigate();
  const mutation = useUpdateCampaignMutation(id);

  const formValues = useMemo<CampaignFormValues>(() => campaignToFormValues(campaign), [campaign]);

  // `values`, not defaultValues + reset(): a refetch re-seeds the form on its own, and
  // keepDirtyValues stops it clobbering a field mid-edit.
  const form = useForm<CampaignFormValues>({
    resolver: campaignFormResolver,
    values: formValues,
    resetOptions: { keepDirtyValues: true },
  });

  const { showDialog, confirmLeave, cancelLeave, bypassNext } = useRouteLeaveGuard(form.formState.isDirty);

  // Fallbacks for handleSave, reset at the top of each submit: SubmitEvent.submitter is missing
  // in Safari < 15.4, and the UnsavedChangeDialog save has no submit event at all.
  const isDraftIntentRef = useRef(false);
  const isDialogSaveRef = useRef(false);
  const [archiveOpen, setArchiveOpen] = useState(false);

  /** Runs the mutation and resets the dirty baseline; a draft save is the same write with status: 'draft'. */
  async function persist(input: ReturnType<typeof formValuesToUpdateInput>): Promise<boolean> {
    try {
      const saved = await mutation.mutateAsync(input);
      // Reset before any navigation reaches the leave guard, or the redirect re-trips it.
      form.reset(campaignToFormValues(saved));
      toast.success(__('Campaign saved.', 'yayloyalty'));
      return true;
    } catch (error) {
      await toastApiError(error, __('Could not save the campaign.', 'yayloyalty'));
      return false;
    }
  }

  // One onSubmit for both save buttons; the submitter says which, with the ref as fallback.
  /* eslint-disable react-hooks/refs -- handleSubmit only invokes these from a real submit, never
     during render; the rule cannot see through its deferred call. */
  const handleSave = form.handleSubmit(
    async (values, event) => {
      const submitter = (event?.nativeEvent as SubmitEvent | undefined)?.submitter as HTMLElement | null | undefined;
      const isDraft = submitter ? submitter.dataset.intent === 'draft' : isDraftIntentRef.current;
      const savingFromDialog = isDialogSaveRef.current;
      isDraftIntentRef.current = false;
      isDialogSaveRef.current = false;

      const input = formValuesToUpdateInput(values);
      const saved = await persist(isDraft ? { ...input, status: 'draft' } : input);

      // confirmLeave is a no-op outside a blocked navigation. Disarm the guard, then navigate.
      if (saved) {
        confirmLeave();

        // A dialog save already proceeds to the originally blocked destination; navigating here
        // would override it.
        if (!savingFromDialog) {
          // The reset()-driven isDirty:false above may not have re-rendered yet — bypass this
          // one navigation so the blocker doesn't re-trip on its own stale closure.
          bypassNext();
          navigate('/campaigns');
        }
      }
    },
    (errors) => {
      // ConditionRow shows each error inline; the toast makes a blocked save impossible to miss.
      if (errors.conditions) {
        toast.error(__('Please fix the highlighted conditions before saving.', 'yayloyalty'));
      }
    },
  );
  /* eslint-enable react-hooks/refs */

  // The header renders from the loaded campaign, never form state.
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);

  return (
    <CampaignEditorContext.Provider value={{ mode: 'edit', campaign, trigger: campaign.trigger }}>
      <FormProvider {...form}>
        <form onSubmit={handleSave}>
          <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
            <Button type="button" variant="link" className="h-auto gap-1 p-0!" onClick={() => navigate('/campaigns')}>
              <ChevronLeft className="size-3.5" />
              {__('Campaigns', 'yayloyalty')}
            </Button>
            <span aria-hidden className="text-muted-foreground">
              ·
            </span>
            <span className="text-muted-foreground">
              {sprintf(
                /* translators: %s: the campaign's display status, lowercased, e.g. "scheduled". */
                __('Editing a %s campaign', 'yayloyalty'),
                statusLabel(displayStatus).toLowerCase(),
              )}
            </span>
          </div>

          <PageLayout
            className="max-w-6xl"
            title={
              <span className="flex flex-wrap items-center gap-2">
                {campaign.name}
                {campaign.is_builtin && (
                  <>
                    <Badge variant="outline" className="uppercase">
                      {__('Default', 'yayloyalty')}
                    </Badge>
                    <YayLoyaltyToolTip
                      trigger={<Lock className="text-muted-foreground size-3.5" />}
                      content={__('Built-in campaigns cannot be archived.', 'yayloyalty')}
                    />
                  </>
                )}
                <Badge variant="outline" className="uppercase">
                  {statusLabel(displayStatus)}
                </Badge>
                {displayStatus === 'scheduled' && campaign.starts_at && (
                  <span className="text-muted-foreground text-sm font-normal">
                    {sprintf(
                      /* translators: %s: relative time until the campaign starts, e.g. "in 3 days". */
                      __('starts %s', 'yayloyalty'),
                      parseWPRelativeDate(campaign.starts_at),
                    )}
                  </span>
                )}
              </span>
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
                onDraftIntent={() => {
                  isDraftIntentRef.current = true;
                }}
                onArchive={() => setArchiveOpen(true)}
                onPerformance={
                  hasPerformancePage(campaign.trigger) ? () => navigate(`/campaigns/${id}/performance`) : undefined
                }
              />
            </div>
          </PageLayout>
        </form>
      </FormProvider>

      <UnsavedChangeDialog
        open={showDialog}
        onOpenChange={(open) => {
          if (!open) {
            cancelLeave();
          }
        }}
        onDiscard={confirmLeave}
        onSave={() => {
          isDialogSaveRef.current = true;
          return handleSave();
        }}
      />

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

/**
 * Create-mode entry point for `campaigns/new/:templateId`, optionally
 * `?from=<id>` for CampaignTable's Duplicate action. Nothing is written
 * until Save — see the chooser's own subtitle.
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

  const { showDialog, confirmLeave, cancelLeave, bypassNext } = useRouteLeaveGuard(form.formState.isDirty);

  // Fallbacks for handleSave, reset at the top of each submit: SubmitEvent.submitter is missing
  // in Safari < 15.4, and the UnsavedChangeDialog save has no submit event at all.
  const isDraftIntentRef = useRef(false);
  const isDialogSaveRef = useRef(false);

  /** A stale key from the old trigger would fail Campaign::config_is_valid() on save. */
  function handleTriggerChange(next: LiteCampaignTrigger) {
    setTrigger(next);
    form.setValue('config', structuredClone(DEFAULT_CAMPAIGN_CONFIG[next]), {
      shouldDirty: true,
      shouldValidate: true,
    });
    form.setValue('conditions', [], { shouldDirty: true, shouldValidate: true });
  }

  async function persist(input: CampaignCreateInput): Promise<boolean> {
    try {
      await mutation.mutateAsync(input);
      toast.success(__('Campaign created.', 'yayloyalty'));
      return true;
    } catch (error) {
      await toastApiError(error, __('Could not create the campaign.', 'yayloyalty'));
      return false;
    }
  }

  // Mirrors CampaignEditorForm's handleSave; see its comments.
  /* eslint-disable react-hooks/refs */
  const handleSave = form.handleSubmit(
    async (values, event) => {
      const submitter = (event?.nativeEvent as SubmitEvent | undefined)?.submitter as HTMLElement | null | undefined;
      const isDraft = submitter ? submitter.dataset.intent === 'draft' : isDraftIntentRef.current;
      const savingFromDialog = isDialogSaveRef.current;
      isDraftIntentRef.current = false;
      isDialogSaveRef.current = false;

      const input = formValuesToCreateInput(values, trigger);
      const created = await persist(isDraft ? { ...input, status: 'draft' } : input);

      if (created) {
        confirmLeave();

        if (!savingFromDialog) {
          bypassNext();
          navigate('/campaigns');
        }
      }
    },
    (errors) => {
      if (errors.conditions) {
        toast.error(__('Please fix the highlighted conditions before saving.', 'yayloyalty'));
      }
    },
  );
  /* eslint-enable react-hooks/refs */

  return (
    <CampaignEditorContext.Provider
      value={{ mode: 'create', campaign: draftCampaign, trigger, onTriggerChange: handleTriggerChange }}
    >
      <FormProvider {...form}>
        <form onSubmit={handleSave}>
          <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
            <Button type="button" variant="link" className="h-auto gap-1 p-0!" onClick={() => navigate('/campaigns')}>
              <ChevronLeft className="size-3.5" />
              {__('Campaigns', 'yayloyalty')}
            </Button>
            <span aria-hidden className="text-muted-foreground">
              ·
            </span>
            <span className="text-muted-foreground">{__('Creating a new campaign', 'yayloyalty')}</span>
          </div>

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
                onDraftIntent={() => {
                  isDraftIntentRef.current = true;
                }}
              />
            </div>
          </PageLayout>
        </form>
      </FormProvider>

      <UnsavedChangeDialog
        open={showDialog}
        onOpenChange={(open) => {
          if (!open) {
            cancelLeave();
          }
        }}
        onDiscard={confirmLeave}
        onSave={() => {
          isDialogSaveRef.current = true;
          return handleSave();
        }}
      />
    </CampaignEditorContext.Provider>
  );
}

/** Placeholder titles only — CampaignEditorSections.tsx's own six section titles are the real ones. */
const EDITOR_SECTION_TITLES = ['basics', 'trigger', 'reward', 'conditions', 'schedule', 'priority'];

/** Mirrors CampaignEditorSections.tsx's numbered-card shell, so the loading state doesn't jump when the real one mounts. */
function CampaignEditorSectionSkeleton() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2.5 text-base">
          <Skeleton className="size-5.5 shrink-0 rounded-full" />
          <Skeleton className="h-4 w-24" />
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <Skeleton className="h-9 w-full" />
        <Skeleton className="h-9 w-2/3" />
      </CardContent>
    </Card>
  );
}
