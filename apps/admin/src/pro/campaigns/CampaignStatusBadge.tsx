import { CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { Badge } from '@/components/ui/badge';
import { campaignStatusBadgeVariant, statusLabel } from './campaign-labels';

/** A campaign's display status as a coloured badge, the same on every screen. */
export function CampaignStatusBadge({ status }: { status: CampaignDisplayStatus }) {
  return <Badge variant={campaignStatusBadgeVariant(status)}>{statusLabel(status)}</Badge>;
}
