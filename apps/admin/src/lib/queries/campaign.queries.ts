import { queryOptions, useQuery } from '@tanstack/react-query';

import { getCampaign, getCampaigns } from '@/lib/api/campaign.api';
import type { CampaignListParams } from '@/lib/schema/campaign.schema';

/** ─── Query Options ─────────────────────────────────── */

export const CAMPAIGN_QUERIES = {
  list: (params: CampaignListParams) =>
    queryOptions({
      queryKey: ['campaign', 'list', params],
      queryFn: () => getCampaigns(params),
    }),

  detail: (id: number) =>
    queryOptions({
      queryKey: ['campaign', 'detail', id],
      queryFn: () => getCampaign(id),
      enabled: id > 0,
    }),
};

/** ─── Hooks ─────────────────────────────────────────── */

export function useCampaignsQuery(params: CampaignListParams = {}) {
  return useQuery(CAMPAIGN_QUERIES.list(params));
}

export function useCampaignQuery(id: number) {
  return useQuery(CAMPAIGN_QUERIES.detail(id));
}
