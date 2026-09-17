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
 * The campaign editor's write surface for `PUT campaigns/{id}` — form
 * provider, layout, and every non-content state. The six sections
 * (Basics/Trigger/Reward/Conditions/Schedule/Priority) render via
 * `CampaignEditorSections.tsx`.
 *
 * FORM LIBRARY: `react-hook-form` + `zodResolver` — the first use of either
 * anywhere in this codebase. `CustomersAdjustPointsDialog.tsx` deliberately
 * skipped both: "Plain `useState` + a manual `zod` check, not
 * `react-hook-form`: two fields, no dependent validation — the library would
 * be pure ceremony here (and nothing else in this app pulls it in yet
 * either)." Every clause of that is false for THIS screen:
 *  1. `components/ui/field.tsx`'s `FieldError` takes RHF's own `FieldError`
 *     shape — the primitive was built for it.
 *  2. `formState.isDirty` feeds `useRouteLeaveGuard` directly below, instead
 *     of hand-rolled dirty-tracking across ~20 trigger-dependent config
 *     fields plus a conditions array.
 *  3. Conditions are a variable-length array — `useFieldArray` territory.
 *  4. Validation is genuinely dependent: `mode` gates `multiplier` vs
 *     `points_per_unit`, `trigger` gates the whole config shape, `starts_at`
 *     gates `ends_at`, `operator: 'between'` gates a pair value.
 * This is a considered divergence from that precedent, not drift from it —
 * see phase-03 (`plans/260916-1547-campaign-editor-ui/`)'s Key Insights for
 * the full reasoning.
 */
export function CampaignEditor({ id }: { id: number }) {
  const campaignQuery = useCampaignQuery(id);

  // `:id` is user-controlled. An invalid value (0, negative, non-numeric —
  // CampaignEditorPage.tsx's Number.parseInt turns the latter into NaN) never
  // reaches the query at all (`enabled: id > 0` on CAMPAIGN_QUERIES.detail),
  // which would otherwise leave `isPending` true forever with no fetch ever
  // starting. Reject it the same way an unreachable route would.
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

  /**
   * `CampaignTable.tsx`'s "Duplicate" action seeds this via router state —
   * display-only. There is no create endpoint wired to this screen: saving
   * from a duplicate-seeded editor updates the ORIGINAL campaign (`id`),
   * exactly like any other edit. A true duplicate that persists as a new row
   * is out of scope until `create_item()`'s list-visibility problem is
   * resolved — see `CampaignTable.tsx`'s "Add campaign" comment and
   * `CampaignRestController`'s class docblock: `create_item()` already
   * persists for real, but `get_items()` still answers from a dummy fixture,
   * so a genuinely new row would vanish from the list on the very next fetch.
   */
  const duplicateSeed = (location.state as { duplicateSeed?: CampaignEditorDuplicateSeed } | null)?.duplicateSeed;

  const formValues = useMemo<CampaignFormValues>(() => {
    const base = campaignToFormValues(campaign);

    return duplicateSeed ? { ...base, name: duplicateSeed.name, status: duplicateSeed.status } : base;
  }, [campaign, duplicateSeed]);

  // Wraps `zodResolver` rather than using it directly: `conditions[].value` is `z.unknown()`
  // (campaign.schema.ts) — its shape genuinely varies per type, so `zodResolver` alone never
  // validates it. `mergeConditionErrors` (campaign-editor.form.ts) runs the per-type checks
  // `CampaignEditorConditions.tsx` used to gate via an out-of-band native `submit` listener,
  // now inside RHF's own resolver lifecycle so it covers every save path — this button, "Save
  // as draft", and `UnsavedChangeDialog`'s Save button alike.
  const resolver: Resolver<CampaignFormValues> = async (values, context, options) => {
    const result = await zodResolver(campaignUpdateInputSchema)(values, context, options);
    return mergeConditionErrors(result, values);
  };

  // `values`, not `defaultValues` + a `useEffect` `reset()` — a refetch
  // (including the one this form's own successful save triggers) re-seeds
  // the form cleanly, with no manual effect to get wrong. See phase-03's Key
  // Insights / Risk Assessment. `resetOptions.keepDirtyValues` protects fields the
  // merchant is actively editing from being clobbered by such a refetch.
  const form = useForm<CampaignFormValues>({
    resolver,
    values: formValues,
    resetOptions: { keepDirtyValues: true },
  });

  const { showDialog, confirmLeave, cancelLeave } = useRouteLeaveGuard(form.formState.isDirty);

  // Two fallback sources of truth, both reset at the top of `handleSave`'s submit callback so a
  // stale value never bleeds into the next, unrelated submit:
  //  - `isDraftIntentRef`: `event.nativeEvent.submitter` is unsupported in Safari < 15.4 — the
  //    draft button's own `onClick` (`CampaignEditorSummaryRail.tsx`) sets this as a fallback for
  //    which button triggered the submit.
  //  - `isDialogSaveRef`: set just before `UnsavedChangeDialog`'s Save button invokes `handleSave`
  //    directly (no `<form>` submit event, so no blocked-navigation context otherwise available)
  //    — see the navigation branch below.
  const isDraftIntentRef = useRef(false);
  const isDialogSaveRef = useRef(false);

  /**
   * `input` already carries `status: 'draft'` when this is a "Save as draft"
   * submit — see `handleSave`'s `submitter` check below. Same mutation, same
   * endpoint, no separate hook (phase-06's Key Insight: draft is a status
   * value, not a different write path).
   */
  async function persist(input: ReturnType<typeof formValuesToUpdateInput>): Promise<boolean> {
    try {
      const saved = await mutation.mutateAsync(input);
      // Reset the dirty baseline to what the SERVER just echoed back
      // (validated, as far as the API is concerned) BEFORE any navigation
      // this save triggers reaches the leave guard — otherwise a post-save
      // redirect would immediately re-trip its own blocker.
      form.reset(campaignToFormValues(saved));
      // Truthful, not more: validation ran and the API answered 200. Nothing
      // here claims the change is durably stored — it isn't yet, see
      // CampaignRestController::update_item()'s SWAP POINT docblock. Reloading
      // this page will show the unchanged fixture row; that is expected,
      // documented behaviour, not a bug to paper over with different copy.
      toast.success(__('Campaign saved.', 'yayloyalty'));
      return true;
    } catch (error) {
      // A 403 with a FORBIDDEN_ERROR_CODES code is already toasted by
      // api.ts's `afterResponse` hook — check the status before toasting
      // again here.
      if (!(error instanceof HTTPError && error.response.status === 403)) {
        toast.error(await getApiErrorMessage(error, __('Could not save the campaign.', 'yayloyalty')));
      }
      return false;
    }
  }

  // One `<form onSubmit>` for both save actions — `CampaignEditorSummaryRail.tsx` renders BOTH
  // buttons as `type="submit"` inside it (not a second click handler each). `event.nativeEvent
  // .submitter` (a standard `SubmitEvent` field) says which button was actually pressed; when
  // it's unavailable (Safari < 15.4), `isDraftIntentRef` — set by the draft button's own
  // `onClick` — is the fallback.
  /* eslint-disable react-hooks/refs -- react-hook-form's `handleSubmit` only ever invokes these
     callbacks from an actual submit (a real event handler), never during render; both refs are
     read once and reset synchronously at the top, before anything async runs. The rule's static
     analysis can't see through `handleSubmit`'s deferred call — the same reason this config
     already turns off its sibling `incompatible-library` check. */
  const handleSave = form.handleSubmit(
    async (values, event) => {
      const submitter = (event?.nativeEvent as SubmitEvent | undefined)?.submitter as HTMLElement | null | undefined;
      const isDraft = submitter ? submitter.dataset.intent === 'draft' : isDraftIntentRef.current;
      const savingFromDialog = isDialogSaveRef.current;
      isDraftIntentRef.current = false;
      isDialogSaveRef.current = false;

      const input = formValuesToUpdateInput(values);
      const saved = await persist(isDraft ? { ...input, status: 'draft' } : input);

      // `confirmLeave` is a no-op outside a blocked navigation — `blocker.proceed`
      // only exists while `blocker.state === 'blocked'`. Safe to call unconditionally.
      // Order matters: disarm the guard, THEN navigate — see `persist()`'s reset above.
      if (saved) {
        confirmLeave();

        // When this save came from `UnsavedChangeDialog`'s Save button, `confirmLeave()` above
        // already proceeds to the ORIGINALLY blocked destination (e.g. "Rewards") — navigating to
        // Campaigns too would override that with the wrong destination. Only navigate here for a
        // normal, in-page Save click.
        if (!savingFromDialog) {
          navigate('/campaigns');
        }
      }
    },
    (errors) => {
      // The resolver-level validation (`mergeConditionErrors`, campaign-editor.form.ts) now
      // populates `formState.errors` through RHF's normal invalid-submit path — `ConditionRow`
      // already renders each `conditions[i].value` error inline, but a toast makes an invalid,
      // blocked save impossible to miss.
      if (errors.conditions) {
        toast.error(__('Please fix the highlighted conditions before saving.', 'yayloyalty'));
      }
    },
  );
  /* eslint-enable react-hooks/refs */

  // Trigger/status/dates never change through form state — sourced from the loaded `Campaign`
  // directly, same as the rest of this header (campaign-editor.form.ts's docblock).
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);

  return (
    <CampaignEditorContext.Provider value={{ campaign }}>
      <FormProvider {...form}>
        <form onSubmit={handleSave}>
          {/* Breadcrumb back to Campaigns — a plain `navigate()` call still goes through
              `useRouteLeaveGuard`'s blocker above, same as any other in-app navigation. */}
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
                {/* Built-in: name stays editable, but no archive/delete surfaces here (delete isn't
                    wired anywhere in this phase — see CampaignTable.tsx's own still-inert entry). */}
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
                {/* Only a 'scheduled' campaign has a future start date worth calling out here —
                    every other display status is already whatever it will stay until next edited. */}
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
              // slug/trigger are read-only for every campaign, not only built-ins — see
              // campaign-editor.form.ts's docblock. Sourced from the loaded Campaign directly, never
              // through form state.
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

/** `CampaignTable.tsx`'s "Duplicate" action seeds the editor with this — see the docblock above. */
export type CampaignEditorDuplicateSeed = { name: string; status: CampaignStatus };
