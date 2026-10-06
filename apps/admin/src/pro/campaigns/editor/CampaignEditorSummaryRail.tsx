import { Check, X } from 'lucide-react';
import { useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { usePurchaseCampaignsQuery } from '@/lib/queries/campaign.queries';
import { findBuiltinCampaign, resolvePurchaseMode } from '@/lib/schema/campaign.schema';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';
import { campaignReadiness, summarizeCampaign } from './campaign-summary.copy';

/**
 * The right rail: "What this means", "Before it can run" and the actions, all derived from live
 * form state via watch(). No cost estimate until a store-wide ledger aggregate exists: a made-up
 * figure on a screen that decides real money is worse than none. Both save buttons are
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
  const { watch, formState } = useFormContext<CampaignFormValues>();
  const { campaign, trigger } = useCampaignEditorContext();
  const values = watch();

  // Only a multiplier campaign reads another campaign; everything else here is form state.
  const needsDefaultPurchase = trigger === 'purchase' && resolvePurchaseMode(values.config) === 'multiplier';
  const purchaseCampaignsQuery = usePurchaseCampaignsQuery(needsDefaultPurchase);
  const defaultPurchaseCampaign = findBuiltinCampaign(purchaseCampaignsQuery.data?.data, 'purchase');

  const sentences = summarizeCampaign(values, {
    trigger,
    defaultPurchaseCampaign,
    // Not isPending: after a failed load it is false too, and the rail would then call a default it never saw inactive.
    isDefaultPurchasePending: needsDefaultPurchase && !purchaseCampaignsQuery.isSuccess,
  });

  const readiness = campaignReadiness(values, {
    trigger,
    defaultPurchaseCampaign,
  });

  const busy = mutation.isPending || formState.isSubmitting;

  return (
    <div className="flex w-full flex-col gap-4 lg:sticky lg:top-6 lg:w-85 lg:shrink-0">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{__('What this means', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3 text-sm">
          {sentences.map((sentence, index) => (
            <p key={index}>{sentence}</p>
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">{__('Before it can run', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <ul className="flex flex-col gap-1.5 text-sm">
            {readiness.items.map((item, index) => (
              <li key={index} className="flex items-start gap-2">
                {item.ok ? (
                  <Check className="text-success mt-0.5 size-4 shrink-0" />
                ) : (
                  <X className="text-destructive mt-0.5 size-4 shrink-0" />
                )}
                <span className={cn(!item.ok && 'text-muted-foreground')}>{item.label}</span>
              </li>
            ))}
          </ul>
          <Separator />
          <p className="text-muted-foreground text-xs">{readiness.derivedStatusLine}</p>
        </CardContent>
      </Card>

      <div className="flex flex-col gap-2">
        <Button type="submit" disabled={busy}>
          {mutation.isPending ? __('Saving…', 'yayloyalty') : __('Save campaign', 'yayloyalty')}
        </Button>
        <Button type="submit" variant="outline" data-intent="draft" disabled={busy} onClick={onDraftIntent}>
          {__('Save as draft', 'yayloyalty')}
        </Button>
        {/* type="button": archiving is the dialog's decision, never a form save. */}
        {onArchive && campaign.status !== 'archived' && (
          <Button type="button" variant="destructive" disabled={busy || campaign.is_builtin} onClick={onArchive}>
            {__('Archive campaign', 'yayloyalty')}
          </Button>
        )}
        {onArchive && campaign.is_builtin && (
          <p className="text-muted-foreground text-center text-xs">
            {__('Default campaigns can’t be archived. You can pause one instead.', 'yayloyalty')}
          </p>
        )}
        <Button type="button" variant="link" disabled={busy} onClick={onCancel}>
          {__('Cancel', 'yayloyalty')}
        </Button>
      </div>
    </div>
  );
}
