import { useMemo } from 'react';
import { type QueryClient, useQuery } from '@tanstack/react-query';

import { can, CAP } from '@/lib/access';
import { MAX_PER_PAGE } from '@/lib/api/paginated';
import { CAMPAIGN_QUERIES } from '@/lib/queries/campaign.queries';

/** One query for both readers, so the tab and the export share a cache entry. */
const CAMPAIGN_NAMES_QUERY = CAMPAIGN_QUERIES.list({ perPage: MAX_PER_PAGE });

type CampaignList = { data: { id: number; name: string }[] };

function toNameMap(list: CampaignList | undefined): Map<number, string> {
  return new Map(list?.data.map((campaign) => [campaign.id, campaign.name]));
}

/**
 * `campaign_id → name` for the ledger tab's "Campaign: …" sub-line.
 * `GET /campaigns` needs MANAGE_CAMPAIGNS but the drawer only VIEW, so the query is gated and degrades to an empty Map.
 * Names can be missing for archived or deleted campaigns, or past the first 100 campaigns.
 */
export function useLedgerCampaignNames(): Map<number, string> {
  const query = useQuery({ ...CAMPAIGN_NAMES_QUERY, enabled: can(CAP.MANAGE_CAMPAIGNS) });

  return useMemo(() => toNameMap(query.data), [query.data]);
}

/** The same map, read once on demand (the ledger export). A failed fetch degrades to an empty Map, never a failed export. */
export async function fetchLedgerCampaignNames(queryClient: QueryClient): Promise<Map<number, string>> {
  if (!can(CAP.MANAGE_CAMPAIGNS)) return new Map();

  return toNameMap(await queryClient.ensureQueryData(CAMPAIGN_NAMES_QUERY).catch(() => undefined));
}
