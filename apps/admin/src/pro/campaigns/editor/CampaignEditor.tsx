import { useMemo, useRef } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { HTTPError } from 'ky';
import { ChevronLeft, Lock } from 'lucide-react';
import { FormProvider, Resolver, useForm } from 'react-hook-form';
import { useLocation, useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { getApiErrorMessage } from '@/lib/api/api';
import { parseWPRelativeDate } from '@/lib/helpers/format.helper';
import { useCampaignQuery, useUpdateCampaignMutation } from '@/lib/queries/campaign.queries';
import {
  Campaign,
  CampaignStatus,
  campaignUpdateInputSchema,
  resolveCampaignDisplayStatus,
} from '@/lib/schema/campaign.schema';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import NotFoundPage from '@/pages/404';
import { statusLabel, triggerLabel } from '../campaign-labels';
import {
  CampaignEditorContext,
  CampaignFormValues,
  campaignToFormValues,
  formValuesToUpdateInput,
  mergeConditionErrors,
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
    return (
      <PageLayout title={<Skeleton className="h-8 w-64" />}>
        <Skeleton className="h-96 w-full" />
      </PageLayout>
    );
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
  const location = useLocation();
  const mutation = useUpdateCampaignMutation(id);

  // Display-only seed from CampaignTable's Duplicate action; saving still updates the original.
  const duplicateSeed = (location.state as { duplicateSeed?: CampaignEditorDuplicateSeed } | null)?.duplicateSeed;

  const formValues = useMemo<CampaignFormValues>(() => {
    const base = campaignToFormValues(campaign);

    return duplicateSeed ? { ...base, name: duplicateSeed.name, status: duplicateSeed.status } : base;
  }, [campaign, duplicateSeed]);

  // conditions[].value is z.unknown(), so the per-type checks run inside the resolver, where
  // they cover every save path.
  const resolver: Resolver<CampaignFormValues> = async (values, context, options) => {
    const result = await zodResolver(campaignUpdateInputSchema)(values, context, options);
    return mergeConditionErrors(result, values);
  };

  // `values`, not defaultValues + reset(): a refetch re-seeds the form on its own, and
  // keepDirtyValues stops it clobbering a field mid-edit.
  const form = useForm<CampaignFormValues>({
    resolver,
    values: formValues,
    resetOptions: { keepDirtyValues: true },
  });

  const { showDialog, confirmLeave, cancelLeave } = useRouteLeaveGuard(form.formState.isDirty);

  // Fallbacks for handleSave, reset at the top of each submit: SubmitEvent.submitter is missing
  // in Safari < 15.4, and the UnsavedChangeDialog save has no submit event at all.
  const isDraftIntentRef = useRef(false);
  const isDialogSaveRef = useRef(false);

  /** Runs the mutation and resets the dirty baseline; a draft save is the same write with status: 'draft'. */
  async function persist(input: ReturnType<typeof formValuesToUpdateInput>): Promise<boolean> {
    try {
      const saved = await mutation.mutateAsync(input);
      // Reset before any navigation reaches the leave guard, or the redirect re-trips it.
      form.reset(campaignToFormValues(saved));
      // "Saved", not "stored": update_item() does not persist yet.
      toast.success(__('Campaign saved.', 'yayloyalty'));
      return true;
    } catch (error) {
      // api.ts's afterResponse hook already toasts a 403.
      if (!(error instanceof HTTPError && error.response.status === 403)) {
        toast.error(await getApiErrorMessage(error, __('Could not save the campaign.', 'yayloyalty')));
      }
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
    <CampaignEditorContext.Provider value={{ campaign }}>
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
                      content={__('Built-in campaigns cannot be deleted.', 'yayloyalty')}
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

/** What CampaignTable's Duplicate action passes through router state. */
export type CampaignEditorDuplicateSeed = { name: string; status: CampaignStatus };
