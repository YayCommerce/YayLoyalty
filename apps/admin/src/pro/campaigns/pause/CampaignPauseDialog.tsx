import { HTTPError } from 'ky';
import { CircleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { getApiErrorMessage } from '@/lib/api/api';
import { useCampaignsQuery, useUpdateCampaignStatusMutation } from '@/lib/queries/campaign.queries';
import { Campaign, resolveCampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { pauseContextLine, pauseStops, resumeLine } from './campaign-pause.copy';

/**
 * Pause confirmation for one active or scheduled campaign. A real
 * confirmation rather than a toggle: pausing a default campaign can stop
 * earning across the whole store. Mount it only while open, like
 * CampaignArchiveDialog.
 */
export function CampaignPauseDialog({ campaign, onClose }: { campaign: Campaign; onClose: () => void }) {
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);
  const campaignsQuery = useCampaignsQuery({ perPage: 100 });
  const stops = pauseStops(campaign, displayStatus, campaignsQuery.data?.data);

  const statusMutation = useUpdateCampaignStatusMutation(campaign.id);
  const busy = statusMutation.isPending;

  async function pause() {
    try {
      await statusMutation.mutateAsync('paused');
      toast.success(__('Campaign paused.', 'yayloyalty'));
      onClose();
    } catch (error) {
      // api.ts's afterResponse hook already toasts a 403.
      if (!(error instanceof HTTPError && error.response.status === 403)) {
        toast.error(await getApiErrorMessage(error, __('Could not pause the campaign.', 'yayloyalty')));
      }
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="gap-0 p-0 sm:max-w-2xl" showCloseButton={!busy}>
        <DialogHeader className="gap-1 border-b p-5 pr-12">
          <DialogTitle>
            {sprintf(
              /* translators: %s: the campaign's name. */
              __('Pause “%s”?', 'yayloyalty'),
              campaign.name,
            )}
          </DialogTitle>
          <DialogDescription>{pauseContextLine(campaign, displayStatus)}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 p-5">
          <Alert variant="muted" className="text-foreground border-l-foreground border-l-4">
            <CircleAlert />
            <AlertTitle className="line-clamp-none font-semibold">{stops.headline}</AlertTitle>
            {stops.lines.length > 0 && (
              <AlertDescription className="text-foreground">
                {stops.lines.map((line) => (
                  <p key={line}>{line}</p>
                ))}
              </AlertDescription>
            )}
          </Alert>

          {/* Deliberately omitted: no setting for storefront earning-text exists yet to link to. */}
          {campaign.is_builtin && campaign.trigger === 'purchase' && (
            <div className="flex flex-col gap-1 rounded-md border p-4">
              <span className="text-sm font-semibold">
                {__('Your storefront will still advertise earning', 'yayloyalty')}
              </span>
              <p className="text-muted-foreground text-sm">
                {__(
                  'Earning messages on the storefront are set separately from campaigns, so they keep promising points until you change them. Customers who already have points can still spend them.',
                  'yayloyalty',
                )}
              </p>
            </div>
          )}

          <p className="text-sm">{resumeLine(campaign.trigger, displayStatus)}</p>
        </div>

        <DialogFooter className="bg-muted/40 border-t p-5 sm:items-center sm:justify-between">
          <span className="text-muted-foreground text-xs">{__('Pausing is reversible at any time', 'yayloyalty')}</span>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button variant="outline" disabled={busy} onClick={onClose}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <Button disabled={busy} onClick={pause}>
              {busy ? __('Pausing…', 'yayloyalty') : __('Pause campaign', 'yayloyalty')}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
