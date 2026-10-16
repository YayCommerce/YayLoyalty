import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useUpdateCampaignStatusMutation } from '@/lib/queries/campaign.queries';
import { Campaign, resolveCampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { toast } from '@/components/ui/toast';
import { pauseBody } from './campaign-pause.copy';

/** Pause confirmation for one active or scheduled campaign; mount it only while open. */
export function CampaignPauseDialog({ campaign, onClose }: { campaign: Campaign; onClose: () => void }) {
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);
  const statusMutation = useUpdateCampaignStatusMutation(campaign.id);
  const busy = statusMutation.isPending;

  async function pause() {
    try {
      await statusMutation.mutateAsync('paused');
      toast.success(__('Campaign paused.', 'yayloyalty'));
      onClose();
    } catch (error) {
      await toastApiError(error, __('Could not pause the campaign.', 'yayloyalty'));
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="gap-0 p-0 sm:max-w-lg" showCloseButton={!busy}>
        <DialogHeader className="gap-2 p-5 pr-12">
          <DialogTitle>
            {sprintf(
              /* translators: %s: the campaign's name. */
              __('Pause “%s”?', 'yayloyalty'),
              campaign.name,
            )}
          </DialogTitle>
          <DialogDescription>{pauseBody(campaign, displayStatus)}</DialogDescription>
        </DialogHeader>

        <DialogFooter className="bg-muted/40 border-t p-5">
          <Button variant="outline" disabled={busy} onClick={onClose}>
            {__('Cancel', 'yayloyalty')}
          </Button>
          <Button disabled={busy} onClick={pause}>
            {busy ? __('Pausing…', 'yayloyalty') : __('Pause campaign', 'yayloyalty')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
