import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';

import { can, CAP } from '@/lib/access';
import { CAMPAIGN_QUERIES } from '@/lib/queries/campaign.queries';

/**
 * `campaign_id → name` for the ledger tab's "Campaign: …" sub-line.
 * `GET /campaigns` needs MANAGE_CAMPAIGNS but the drawer only VIEW, so the query is gated and degrades to an empty Map.
 * Names can be missing for archived or deleted campaigns, or past the first 100 campaigns.
 */
export function useLedgerCampaignNames(): Map<number, string> {
  const query = useQuery({
    ...CAMPAIGN_QUERIES.list({ perPage: 100 }),
    enabled: can(CAP.MANAGE_CAMPAIGNS),
  });

  return useMemo(() => {
    if (!query.data) return new Map<number, string>();

    return new Map(query.data.data.map((campaign) => [campaign.id, campaign.name]));
  }, [query.data]);
}
